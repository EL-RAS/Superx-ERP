<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Support\Collection;

/**
 * Financial and operational report generators. Kept query-free of request /
 * response concerns so the controller stays a thin JSON adapter and every
 * report's computation lives in one place, business-scoped via the standard
 * BelongsToBusiness global scope.
 */
class ReportService
{
    public function trialBalance(?string $dateFrom, ?string $dateTo): array
    {
        $accounts = Account::where('is_active', true)
            ->orderBy('code', 'asc')
            ->get();

        $result = $accounts->map(function ($account) use ($dateFrom, $dateTo) {
            [$debit, $credit] = $this->accountTotals($account, $dateFrom, $dateTo);

            return [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'debit' => round($debit, 4),
                'credit' => round($credit, 4),
                'balance' => $this->signedBalance($account->type, $debit, $credit),
            ];
        })->filter(fn ($row) => $row['debit'] != 0 || $row['credit'] != 0);

        $totalDebit = $result->sum('debit');
        $totalCredit = $result->sum('credit');

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'accounts' => $result->values(),
            'total_debit' => round($totalDebit, 4),
            'total_credit' => round($totalCredit, 4),
            'is_balanced' => abs(round($totalDebit, 2) - round($totalCredit, 2)) < 0.01,
        ];
    }

    public function profitAndLoss(string $dateFrom, string $dateTo): array
    {
        $revenue = $this->getAccountTypeTotals('revenue', $dateFrom, $dateTo);
        $expense = $this->getAccountTypeTotals('expense', $dateFrom, $dateTo);

        $totalRevenue = $revenue->sum('credit') - $revenue->sum('debit');
        $totalExpense = $expense->sum('debit') - $expense->sum('credit');
        $netIncome = round($totalRevenue - $totalExpense, 4);

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'revenue' => [
                'accounts' => $revenue,
                'total' => round($totalRevenue, 4),
            ],
            'expense' => [
                'accounts' => $expense,
                'total' => round($totalExpense, 4),
            ],
            'net_income' => $netIncome,
        ];
    }

    public function balanceSheet(string $dateTo): array
    {
        $asset = $this->getAccountTypeTotals('asset', null, $dateTo);
        $liability = $this->getAccountTypeTotals('liability', null, $dateTo);
        $equity = $this->getAccountTypeTotals('equity', null, $dateTo);

        $totalAsset = $asset->sum('debit') - $asset->sum('credit');
        $totalLiability = $liability->sum('credit') - $liability->sum('debit');
        $totalEquity = $equity->sum('credit') - $equity->sum('debit');

        $revenueToDate = $this->getAccountTypeTotals('revenue', null, $dateTo);
        $expenseToDate = $this->getAccountTypeTotals('expense', null, $dateTo);
        $retainedEarnings = ($revenueToDate->sum('credit') - $revenueToDate->sum('debit'))
            - ($expenseToDate->sum('debit') - $expenseToDate->sum('credit'));

        $equity->push([
            'account_id' => null,
            'code' => '--',
            'name' => 'Retained Earnings',
            'type' => 'equity',
            'debit' => round(max(0, -$retainedEarnings), 4),
            'credit' => round(max(0, $retainedEarnings), 4),
            'balance' => round($retainedEarnings, 4),
        ]);

        return [
            'date_to' => $dateTo,
            'asset' => [
                'accounts' => $asset,
                'total' => round($totalAsset, 4),
            ],
            'liability' => [
                'accounts' => $liability,
                'total' => round($totalLiability, 4),
            ],
            'equity' => [
                'accounts' => $equity,
                'total' => round($totalEquity, 4),
                'retained_earnings' => round($retainedEarnings, 4),
            ],
            'total_liabilities_equity' => round($totalLiability + $totalEquity + $retainedEarnings, 4),
            'is_balanced' => abs(round($totalAsset, 2) - round($totalLiability + $totalEquity + $retainedEarnings, 2)) < 0.01,
        ];
    }

    public function cashFlow(string $dateFrom, string $dateTo): array
    {
        $operating = $this->getCashFlowByCategory('operating', $dateFrom, $dateTo);
        $investing = $this->getCashFlowByCategory('investing', $dateFrom, $dateTo);
        $financing = $this->getCashFlowByCategory('financing', $dateFrom, $dateTo);

        $netCash = round($operating + $investing + $financing, 4);

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'operating' => round($operating, 4),
            'investing' => round($investing, 4),
            'financing' => round($financing, 4),
            'net_cash_flow' => $netCash,
        ];
    }

    public function generalLedger(Account $account, ?string $dateFrom, ?string $dateTo): array
    {
        $query = JournalEntryLine::where('account_id', $account->id)
            ->with('journalEntry:id,entry_number,date,description,is_posted')
            ->whereHas('journalEntry', function ($q) use ($dateFrom, $dateTo) {
                $q->where('is_posted', true);
                if ($dateFrom) {
                    $q->where('date', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $q->where('date', '<=', $dateTo);
                }
            })
            ->orderBy('created_at', 'asc')
            ->get();

        $runningBalance = 0;
        $entries = $query->map(function ($line) use (&$runningBalance, $account) {
            $runningBalance += $line->debit - $line->credit;

            return [
                'id' => $line->id,
                'date' => $line->journalEntry->date,
                'entry_number' => $line->journalEntry->entry_number,
                'description' => $line->description ?? $line->journalEntry->description,
                'debit' => round($line->debit, 4),
                'credit' => round($line->credit, 4),
                'balance' => match ($account->type) {
                    'asset', 'expense' => round($runningBalance, 4),
                    default => round(-$runningBalance, 4),
                },
            ];
        });

        return [
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
            ],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'entries' => $entries,
        ];
    }

    public function salesSummary(string $dateFrom, string $dateTo): array
    {
        $invoices = Invoice::with(['items.product'])
            ->where('status', '!=', 'void')
            ->when($dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('created_at', '<=', $dateTo))
            ->get();

        $items = $invoices->flatMap->items;

        $netRevenue = round($invoices->sum(fn ($inv) => (float) $inv->net_amount - (float) $inv->tax_amount), 2);
        $taxAmount = round($invoices->sum(fn ($inv) => (float) $inv->tax_amount), 2);
        $grossRevenue = round($netRevenue + $taxAmount, 2);
        $totalQuantity = round($items->sum(fn ($it) => (float) $it->quantity), 2);
        $totalCogs = round($items->sum(fn ($it) => $this->itemCogs($it)), 2);
        $grossProfit = round($netRevenue - $totalCogs, 2);
        $grossMargin = $netRevenue > 0 ? round($grossProfit / $netRevenue * 100, 2) : 0;

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'summary' => [
                'invoice_count' => $invoices->count(),
                'total_quantity' => $totalQuantity,
                'total_revenue' => $netRevenue,
                'net_revenue' => $netRevenue,
                'tax_amount' => $taxAmount,
                'gross_revenue' => $grossRevenue,
                'total_cogs' => $totalCogs,
                'gross_profit' => $grossProfit,
                'gross_margin' => $grossMargin,
            ],
            'by_product' => $this->aggregateSalesItems($items, 'product'),
            'by_category' => $this->aggregateSalesItems($items, 'category'),
        ];
    }

    public function stockValuation(): array
    {
        $products = Product::with(['batches' => function ($q) {
            $q->where(function ($qq) {
                $qq->whereNull('expiry_date')
                    ->orWhere('expiry_date', '>=', now());
            })->whereRaw('(quantity - quantity_sold) > 0');
        }])->get();

        $rows = [];
        $totalUnits = 0;
        $totalValue = 0;

        foreach ($products as $product) {
            if ($product->has_batch) {
                $quantity = (float) $product->batches->sum(fn ($b) => (float) $b->quantity - (float) $b->quantity_sold);
                $value = (float) $product->batches->sum(fn ($b) => ((float) $b->quantity - (float) $b->quantity_sold) * (float) $b->cost_per_unit);
            } else {
                $quantity = (float) $product->stock_quantity;
                $value = $quantity * (float) $product->cost;
            }

            if ($quantity <= 0.001) {
                continue;
            }

            $value = round($value, 2);
            $totalUnits += $quantity;
            $totalValue += $value;

            $rows[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit' => $product->unit,
                'category' => $product->category,
                'quantity' => round($quantity, 2),
                'cost_per_unit' => round($quantity > 0 ? $value / $quantity : 0, 2),
                'value' => $value,
            ];
        }

        usort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);

        return [
            'generated_at' => now()->toDateString(),
            'summary' => [
                'product_count' => count($rows),
                'total_units' => round($totalUnits, 2),
                'total_value' => round($totalValue, 2),
            ],
            'products' => $rows,
        ];
    }

    public function supplierAging(): array
    {
        $asOf = now()->startOfDay();

        $orders = PurchaseOrder::with(['supplier', 'payments'])
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->orderBy('supplier_id')
            ->orderBy('created_at')
            ->get();

        $aggregates = [];
        $totalOutstanding = 0;

        foreach ($orders as $po) {
            $paid = (float) $po->payments->where('status', 'completed')->sum('amount');
            $balance = round((float) $po->total_amount - $paid, 2);

            if ($balance <= 0.005) {
                continue;
            }

            $dueDate = ($po->expected_delivery ?? $po->created_at)->copy()->startOfDay();
            $daysPastDue = (int) $dueDate->diffInDays($asOf, false);
            $bucket = $daysPastDue <= 0 ? 'current' : ($daysPastDue <= 30 ? 'd30' : ($daysPastDue <= 60 ? 'd60' : 'd90'));

            $key = (string) $po->supplier_id;
            $agg = $aggregates[$key] ?? [
                'supplier_id' => $po->supplier_id,
                'name' => $po->supplier?->name ?? 'Unknown Supplier',
                'phone' => $po->supplier?->phone,
                'orders_count' => 0,
                'outstanding' => 0,
                'buckets' => ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0],
                'orders' => [],
            ];

            $agg['orders_count']++;
            $agg['outstanding'] += $balance;
            $agg['buckets'][$bucket] += $balance;
            $agg['orders'][] = [
                'id' => $po->id,
                'order_number' => $po->order_number,
                'order_date' => $po->created_at->toDateString(),
                'due_date' => $po->expected_delivery?->toDateString(),
                'status' => $po->status,
                'total' => round((float) $po->total_amount, 2),
                'paid' => round($paid, 2),
                'balance' => $balance,
                'days_past_due' => $daysPastDue,
            ];

            $aggregates[$key] = $agg;
            $totalOutstanding += $balance;
        }

        $suppliers = collect(array_values($aggregates))->map(function ($s) {
            $s['outstanding'] = round($s['outstanding'], 2);
            foreach (['current', 'd30', 'd60', 'd90'] as $b) {
                $s['buckets'][$b] = round($s['buckets'][$b], 2);
            }

            return $s;
        })->sortByDesc('outstanding')->values();

        return [
            'as_of' => $asOf->toDateString(),
            'total_outstanding' => round($totalOutstanding, 2),
            'suppliers' => $suppliers,
        ];
    }

    /**
     * @return array{0: float, 1: float} [debit, credit] for a single account
     *                                   over the window.
     */
    private function accountTotals(Account $account, ?string $dateFrom, ?string $dateTo): array
    {
        $query = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', function ($q) use ($dateFrom, $dateTo) {
                $q->where('is_posted', true);
                if ($dateFrom) {
                    $q->where('date', '>=', $dateFrom);
                }
                if ($dateTo) {
                    $q->where('date', '<=', $dateTo);
                }
            });

        return [(clone $query)->sum('debit'), (clone $query)->sum('credit')];
    }

    private function itemCogs(InvoiceItem $item): float
    {
        $deductions = $item->metadata['deductions'] ?? null;
        if (is_array($deductions) && count($deductions) > 0) {
            return (float) collect($deductions)->sum(
                fn ($d) => (float) ($d['unit_cost'] ?? 0) * (float) ($d['quantity'] ?? 0)
            );
        }

        return (float) $item->quantity * (float) ($item->product?->cost ?? 0);
    }

    private function aggregateSalesItems(Collection $items, string $group): array
    {
        $aggregates = [];

        foreach ($items as $item) {
            $revenue = $item->total !== null
                ? round((float) $item->total - (float) $item->tax_amount, 2)
                : round((float) $item->quantity * (float) $item->unit_price, 2);
            $cogs = $this->itemCogs($item);

            if ($group === 'product') {
                $key = $item->product_id ? (string) $item->product_id : 'manual:'.$item->name;
                $aggregates[$key] ??= [
                    'product_id' => $item->product_id,
                    'name' => $item->product?->name ?? $item->name,
                    'sku' => $item->product?->sku,
                    'category' => $item->product?->category,
                    'quantity' => 0,
                    'revenue' => 0,
                    'cogs' => 0,
                ];
                $aggregates[$key]['quantity'] += (float) $item->quantity;
                $aggregates[$key]['revenue'] += $revenue;
                $aggregates[$key]['cogs'] += $cogs;
            } else {
                $key = $item->product?->category ?: 'uncategorized';
                $aggregates[$key] ??= [
                    'category' => $item->product?->category,
                    'quantity' => 0,
                    'revenue' => 0,
                    'cogs' => 0,
                ];
                $aggregates[$key]['quantity'] += (float) $item->quantity;
                $aggregates[$key]['revenue'] += $revenue;
                $aggregates[$key]['cogs'] += $cogs;
            }
        }

        return collect(array_values($aggregates))->map(function ($row) {
            $profit = round($row['revenue'] - $row['cogs'], 2);

            return array_merge($row, [
                'quantity' => round($row['quantity'], 2),
                'revenue' => round($row['revenue'], 2),
                'cogs' => round($row['cogs'], 2),
                'profit' => $profit,
                'margin' => $row['revenue'] > 0 ? round($profit / $row['revenue'] * 100, 2) : 0,
            ]);
        })->sortByDesc('revenue')->values()->toArray();
    }

    private function getAccountTypeTotals(string $type, ?string $dateFrom, string $dateTo): Collection
    {
        $accounts = Account::where('type', $type)->where('is_active', true)->orderBy('code')->get();

        return $accounts->map(function ($account) use ($dateFrom, $dateTo) {
            [$debit, $credit] = $this->accountTotals($account, $dateFrom, $dateTo);

            return [
                'account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'debit' => round($debit, 4),
                'credit' => round($credit, 4),
                'balance' => $this->signedBalance($account->type, $debit, $credit),
            ];
        })->filter(fn ($row) => $row['debit'] != 0 || $row['credit'] != 0)->values();
    }

    private function getCashFlowByCategory(string $category, string $dateFrom, string $dateTo): float
    {
        $financingRefs = ['opening_balance', 'inventory_opening', 'owner_capital', 'owner_deposit', 'share_capital', 'loan', 'loan_received'];
        $investingRefs = ['asset_purchase', 'fixed_asset', 'capex', 'purchase_asset'];

        $cashAccountIds = Account::where('is_active', true)
            ->whereIn('code', ['1005', '1010', '1020'])
            ->pluck('id');

        if ($cashAccountIds->isEmpty()) {
            return 0.0;
        }

        $entries = JournalEntry::with(['lines' => fn ($q) => $q->whereIn('account_id', $cashAccountIds)])
            ->where('is_posted', true)
            ->where('date', '>=', $dateFrom)
            ->where('date', '<=', $dateTo)
            ->get();

        $total = 0.0;
        foreach ($entries as $entry) {
            $ref = (string) $entry->reference_type;
            $matches = match ($category) {
                'operating' => ! in_array($ref, array_merge($financingRefs, $investingRefs), true),
                'investing' => in_array($ref, $investingRefs, true),
                'financing' => in_array($ref, $financingRefs, true),
                default => false,
            };
            if (! $matches) {
                continue;
            }

            foreach ($entry->lines as $line) {
                $total += round((float) $line->debit - (float) $line->credit, 2);
            }
        }

        return round($total, 4);
    }

    private function signedBalance(string $accountType, float $debit, float $credit): float
    {
        return round(match ($accountType) {
            'asset', 'expense' => $debit - $credit,
            default => $credit - $debit,
        }, 4);
    }
}
