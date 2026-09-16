<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FiscalYearController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $years = FiscalYear::orderBy('start_date', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($years);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
        ]);

        $validated['business_id'] = $request->user()->business_id;

        $year = FiscalYear::create($validated);

        return response()->json($year, 201);
    }

    public function show(FiscalYear $fiscalYear): JsonResponse
    {
        return response()->json($fiscalYear);
    }

    public function update(Request $request, FiscalYear $fiscalYear): JsonResponse
    {
        if ($fiscalYear->is_closed) {
            return response()->json(['message' => 'Cannot edit a closed fiscal year.'], 422);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
        ]);

        $fiscalYear->update($validated);

        return response()->json($fiscalYear);
    }

    public function destroy(FiscalYear $fiscalYear): JsonResponse
    {
        if ($fiscalYear->is_closed) {
            return response()->json(['message' => 'Cannot delete a closed fiscal year.'], 422);
        }

        $fiscalYear->delete();

        return response()->json(['message' => 'Fiscal year deleted.']);
    }

    public function close(FiscalYear $fiscalYear): JsonResponse
    {
        if ($fiscalYear->is_closed) {
            return response()->json(['message' => 'Fiscal year is already closed.'], 422);
        }

        DB::transaction(function () use ($fiscalYear) {
            $dateFrom = $fiscalYear->start_date->toDateString();
            $dateTo = $fiscalYear->end_date->toDateString();

            $accounting = app(\App\Services\AccountingService::class);
            $accounting->ensureChartOfAccounts($fiscalYear->business_id);

            $revenueAccounts = Account::where('type', 'revenue')->where('is_active', true)->get();
            $expenseAccounts = Account::where('type', 'expense')->where('is_active', true)->get();

            $lines = [];
            $totalDebit = 0.0;
            $totalCredit = 0.0;
            $totalRevenue = 0.0;
            $totalExpense = 0.0;

            foreach ($revenueAccounts as $account) {
                $sums = JournalEntryLine::where('account_id', $account->id)
                    ->whereHas('journalEntry', fn ($q) => $q
                        ->where('is_posted', true)
                        ->where('date', '>=', $dateFrom)
                        ->where('date', '<=', $dateTo))
                    ->selectRaw('COALESCE(SUM(credit), 0) as credit, COALESCE(SUM(debit), 0) as debit')
                    ->first();

                $balance = round((float) $sums->credit - (float) $sums->debit, 2);
                $totalRevenue += $balance;

                if (abs($balance) > 0.01) {
                    $lines[] = [
                        'account_id' => $account->id,
                        'debit' => $balance,
                        'credit' => 0,
                        'description' => 'Close '.$account->name.' for '.$fiscalYear->name,
                    ];
                    $totalDebit += $balance;
                }
            }

            foreach ($expenseAccounts as $account) {
                $sums = JournalEntryLine::where('account_id', $account->id)
                    ->whereHas('journalEntry', fn ($q) => $q
                        ->where('is_posted', true)
                        ->where('date', '>=', $dateFrom)
                        ->where('date', '<=', $dateTo))
                    ->selectRaw('COALESCE(SUM(credit), 0) as credit, COALESCE(SUM(debit), 0) as debit')
                    ->first();

                $balance = round((float) $sums->debit - (float) $sums->credit, 2);
                $totalExpense += $balance;

                if (abs($balance) > 0.01) {
                    $lines[] = [
                        'account_id' => $account->id,
                        'debit' => 0,
                        'credit' => $balance,
                        'description' => 'Close '.$account->name.' for '.$fiscalYear->name,
                    ];
                    $totalCredit += $balance;
                }
            }

            $netIncome = round($totalDebit - $totalCredit, 2);

            if (abs($netIncome) > 0.01) {
                $retainedEarningsAccount = $accounting->accountByCode($fiscalYear->business_id, '3020')
                    ?? $accounting->accountByCode($fiscalYear->business_id, '3200')
                    ?? Account::where('business_id', $fiscalYear->business_id)
                        ->where('type', 'equity')
                        ->where('name', 'ilike', '%retained%earnings%')
                        ->first();

                if ($netIncome > 0) {
                    $lines[] = [
                        'account_id' => $retainedEarningsAccount->id,
                        'debit' => 0,
                        'credit' => $netIncome,
                        'description' => 'Net income for '.$fiscalYear->name,
                    ];
                } else {
                    $lines[] = [
                        'account_id' => $retainedEarningsAccount->id,
                        'debit' => abs($netIncome),
                        'credit' => 0,
                        'description' => 'Net loss for '.$fiscalYear->name,
                    ];
                }
            }

            if (count($lines) > 0) {
                $accounting->post($fiscalYear->business_id, [
                    'date' => $dateTo,
                    'description' => "Year-end closing for {$fiscalYear->name}",
                    'reference_type' => 'fiscal_year',
                    'reference_id' => $fiscalYear->id,
                    'user_id' => auth()->id(),
                    'entry_prefix' => 'YE',
                ], $lines);
            }

            $fiscalYear->update([
                'is_closed' => true,
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'metadata' => array_merge($fiscalYear->metadata ?? [], [
                    'total_revenue' => $totalRevenue,
                    'total_expense' => $totalExpense,
                    'net_income' => $netIncome,
                ]),
            ]);
        });

        return response()->json($fiscalYear);
    }
}
