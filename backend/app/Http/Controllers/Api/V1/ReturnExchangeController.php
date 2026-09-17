<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ReturnExchange;
use App\Models\ReturnExchangeItem;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Rules\ProductQuantity;
use App\Services\AccountingService;
use App\Services\DocumentNumberService;
use App\Services\StockService;
use App\Services\TaxCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReturnExchangeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ReturnExchange::query()->with([
            'invoice:id,invoice_number,customer_id,net_amount',
            'invoice.customer:id,name',
            'user:id,name',
            'items.product:id,name,sku',
            'exchangeInvoice:id,invoice_number',
        ]);

        if ($request->has('type') && $request->input('type') !== 'all') {
            $query->where('type', $request->input('type'));
        }

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('return_number', 'ilike', "%{$search}%")
                    ->orWhereHas('invoice', fn ($iq) => $iq->where('invoice_number', 'ilike', "%{$search}%"));
            });
        }

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        return response()->json($query->orderByDesc('created_at')->paginate($request->integer('per_page', 10)));
    }

    public function show(ReturnExchange $returnExchange): JsonResponse
    {
        $returnExchange->load([
            'invoice:id,invoice_number,customer_id,net_amount,created_at',
            'invoice.customer:id,name,phone,email',
            'invoice.user:id,name',
            'user:id,name',
            'items.product:id,name,sku',
            'items.invoiceItem:id,name,unit_price,quantity',
            'exchangeInvoice:id,invoice_number,net_amount',
        ]);

        return response()->json($returnExchange);
    }

    /**
     * Per-line returnable quantities for a given invoice (original quantity
     * minus what has already been returned through this feature or through
     * legacy inventory-adjustment returns).
     */
    public function returnable(Request $request, Invoice $invoice): JsonResponse
    {
        if ((string) $invoice->business_id !== (string) $request->user()->business_id) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        if ($invoice->status === 'void') {
            return response()->json(['message' => 'Voided invoices cannot be returned.'], 422);
        }

        $invoice->load('items.product:id,name,sku,unit,has_batch', 'customer:id,name');

        $itemIds = $invoice->items->pluck('id');
        $productIds = $invoice->items->pluck('product_id')->filter();

        $alreadyReturnedMap = ReturnExchangeItem::whereIn('invoice_item_id', $itemIds)
            ->get()
            ->groupBy('invoice_item_id')
            ->map(fn ($rows) => (float) $rows->sum('quantity'));

        // Feature-created returns are already captured by ReturnExchangeItem;
        // only count legacy adjustments that weren't created through this flow.
        $adjustmentMap = InventoryAdjustment::where('type', 'return')
            ->whereIn('product_id', $productIds)
            ->get()
            ->filter(fn ($adj) => ($adj->metadata['invoice_id'] ?? null) == $invoice->id
                && empty($adj->metadata['return_exchange_id']))
            ->groupBy('product_id')
            ->map(fn ($rows) => (float) $rows->sum(fn ($r) => abs((float) $r->quantity_adjusted)));

        $items = $invoice->items->map(function (InvoiceItem $item) use ($alreadyReturnedMap, $adjustmentMap) {
            $original = (float) $item->quantity;
            $already = ($alreadyReturnedMap->get($item->id) ?? 0) + ($adjustmentMap->get($item->product_id) ?? 0);

            return [
                'invoice_item_id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->name,
                'sku' => $item->product?->sku,
                'unit' => $item->product?->unit,
                'unit_price' => round((float) $item->unit_price, 2),
                'tax_rate' => round((float) $item->tax_rate, 2),
                'quantity' => $original,
                'already_returned' => round($already, 2),
                'returnable' => round(max(0, $original - $already), 2),
                'batch_id' => $item->product?->has_batch ? ($item->metadata['deductions'][0]['batch_id'] ?? null) : null,
                'has_batch' => (bool) ($item->product?->has_batch ?? false),
            ];
        });

        return response()->json([
            'invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $invoice->customer_id,
                'customer_name' => $invoice->customer?->name,
                'net_amount' => $invoice->net_amount,
            ],
            'items' => $items->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:return,exchange',
            'invoice_id' => 'required|exists:invoices,id',
            'refund_method' => 'required_if:type,return|nullable|string|in:cash,bank,credit',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.invoice_item_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.reason' => 'nullable|string',
            'exchange_items' => 'required_if:type,exchange|array|min:1',
            'exchange_items.*.product_id' => 'required|exists:products,id',
            'exchange_items.*.quantity' => ['required', 'numeric', 'min:0.01', ProductQuantity::forItems(fn (string $attribute) => $request->input(str_replace('.quantity', '.product_id', $attribute)))],
            'exchange_items.*.unit_price' => 'required|numeric|min:0',
            'exchange_items.*.tax_rate' => 'nullable|numeric|min:0',
            'exchange_difference_method' => 'nullable|string|in:cash,card,bank_transfer,check,mobile',
        ]);

        return DocumentNumberService::retryOnConflict(function () use ($request, $validated) {
            return DB::transaction(function () use ($request, $validated) {
                $businessId = $request->user()->business_id;
                $userId = $request->user()->id;

                $invoice = Invoice::findOrFail($validated['invoice_id']);
                if ((string) $invoice->business_id !== (string) $businessId) {
                    return response()->json(['message' => 'Invoice not found.'], 404);
                }
                if ($invoice->status === 'void') {
                    return response()->json(['message' => 'Voided invoices cannot be returned.'], 422);
                }

                $isExchange = $validated['type'] === 'exchange';
                $refundMethod = $isExchange ? null : $validated['refund_method'];

                $openShift = Shift::openFor($businessId, $userId);

                $returnExchange = ReturnExchange::create([
                    'business_id' => $businessId,
                    'user_id' => $userId,
                    'invoice_id' => $invoice->id,
                    'return_number' => 'RET-'.strtoupper(Str::random(8)),
                    'type' => $validated['type'],
                    'status' => 'completed',
                    'refund_method' => $refundMethod,
                    'notes' => $validated['notes'] ?? null,
                ]);

                $invoice->load('items');
                $accounting = app(AccountingService::class);
                $returnedValue = 0.0;

                foreach ($validated['items'] as $line) {
                    $returnedValue += $this->processReturnLine(
                        $request, $invoice, $line, $returnExchange, $refundMethod, $isExchange
                    );
                }

                $returnExchange->update(['returned_amount' => round($returnedValue, 2)]);

                $exchangeInvoice = null;
                $exchangedAmount = 0.0;

                if ($isExchange) {
                    [$exchangeInvoice, $exchangedAmount] = $this->createExchangeInvoice(
                        $request, $invoice, $returnExchange, $validated, $openShift
                    );
                }

                $difference = round($exchangedAmount - $returnedValue, 2);

                if ($isExchange) {
                    $this->settleExchangeDifference($request, $returnExchange, $exchangeInvoice, $difference, $openShift);
                }

                $returnExchange->update([
                    'exchanged_amount' => round($exchangedAmount, 2),
                    'difference_amount' => $difference,
                    'refund_amount' => $isExchange ? max(0, -$difference) : round($returnedValue, 2),
                    'exchange_invoice_id' => $exchangeInvoice?->id,
                ]);

                $returnExchange->load([
                    'invoice:id,invoice_number,customer_id,net_amount',
                    'invoice.customer:id,name',
                    'user:id,name',
                    'items.product:id,name,sku',
                    'exchangeInvoice:id,invoice_number,net_amount',
                ]);

                return response()->json($returnExchange, 201);
            });
        }, 'invoices');
    }

    /**
     * Restock one returned line: create the inventory adjustment (type=return),
     * reverse COGS and revenue via the accounting service, record a stock
     * movement and persist the return-exchanges line item.
     *
     * @param  array<string, mixed>  $line
     */
    private function processReturnLine(Request $request, Invoice $invoice, array $line, ReturnExchange $returnExchange, ?string $refundMethod, bool $isExchange): float
    {
        $businessId = $request->user()->business_id;
        $userId = $request->user()->id;

        $invoiceItem = $invoice->items->firstWhere('id', $line['invoice_item_id']);
        if (! $invoiceItem) {
            throw new \RuntimeException('Returned item does not belong to this invoice.');
        }
        if (empty($invoiceItem->product_id)) {
            throw new \RuntimeException("Item \"{$invoiceItem->name}\" has no linked product and cannot be returned.");
        }

        $qty = (float) $line['quantity'];

        $already = (float) ReturnExchangeItem::where('invoice_item_id', $invoiceItem->id)->sum('quantity')
            + (float) InventoryAdjustment::where('type', 'return')
                ->where('product_id', $invoiceItem->product_id)
                ->get()
                ->filter(fn ($adj) => ($adj->metadata['invoice_id'] ?? null) == $invoice->id
                    && empty($adj->metadata['return_exchange_id']))
                ->sum(fn ($adj) => abs((float) $adj->quantity_adjusted));

        $original = (float) $invoiceItem->quantity;
        if ($qty > $original - $already + 0.001) {
            throw new InsufficientStockException("Cannot return more than the remaining quantity for \"{$invoiceItem->name}\".");
        }

        $product = Product::findOrFail($invoiceItem->product_id);
        $batch = $this->resolveBatch($invoiceItem, $product);

        $quantityBefore = (float) $product->stock_quantity;

        $adjustment = InventoryAdjustment::create([
            'business_id' => $businessId,
            'user_id' => $userId,
            'product_id' => $product->id,
            'batch_id' => $batch?->id,
            'adjustment_number' => 'ADJ-'.strtoupper(Str::random(8)),
            'type' => 'return',
            'quantity_before' => $quantityBefore,
            'quantity_adjusted' => $qty,
            'quantity_after' => round($quantityBefore + $qty, 2),
            'reason' => $line['reason'] ?? null,
            'notes' => $line['reason'] ?? null,
            'status' => 'completed',
            'metadata' => [
                'invoice_id' => $invoice->id,
                'return_exchange_id' => $returnExchange->id,
                'refund_method' => $refundMethod,
            ],
        ]);

        if ($batch) {
            $batch->decrement('quantity_sold', $qty);
            $product->fresh()->refreshMetrics();
        } else {
            $product->increment('stock_quantity', $qty);
        }

        $unitCost = (float) ($batch?->cost_per_unit ?? 0);
        if ($unitCost <= 0) {
            $unitCost = (float) $product->cost;
        }

        $accounting = app(AccountingService::class);
        $accounting->postInventoryAdjustmentEntry($businessId, $adjustment, $qty * $unitCost, $userId);

        $revenue = round($qty * (float) $invoiceItem->unit_price, 2);
        $tax = round($revenue * ((float) $invoiceItem->tax_rate / 100), 2);

        if ($revenue + $tax > 0) {
            $accounting->postSalesReturnEntry($businessId, $adjustment, $revenue, $tax, $userId, $refundMethod ?? 'credit');
        }

        StockMovement::create([
            'business_id' => $businessId,
            'product_id' => $product->id,
            'quantity' => $qty,
            'type' => 'addition',
            'reference_type' => $isExchange ? 'exchange_return' : 'sales_return',
            'reference_id' => $returnExchange->id,
            'notes' => ($isExchange ? 'Exchange return' : 'Sales return').' - '.$returnExchange->return_number,
        ]);

        ReturnExchangeItem::create([
            'return_exchange_id' => $returnExchange->id,
            'invoice_item_id' => $invoiceItem->id,
            'product_id' => $product->id,
            'batch_id' => $batch?->id,
            'quantity' => $qty,
            'unit_price' => (float) $invoiceItem->unit_price,
            'tax_rate' => (float) $invoiceItem->tax_rate,
            'reason' => $line['reason'] ?? null,
            'is_exchange' => $isExchange,
        ]);

        return $revenue + $tax;
    }

    private function resolveBatch(InvoiceItem $invoiceItem, Product $product): ?ProductBatch
    {
        if (! $product->has_batch) {
            return null;
        }

        $batchId = $invoiceItem->metadata['deductions'][0]['batch_id'] ?? null;
        if ($batchId) {
            $batch = ProductBatch::find($batchId);
            if ($batch && (string) $batch->product_id === (string) $product->id) {
                return $batch;
            }
        }

        return ProductBatch::where('product_id', $product->id)
            ->where('is_active', true)
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->first();
    }

    /**
     * Create the sale invoice for the exchanged goods at full value (stock is
     * deducted, the sale entry posted, the shift totals bumped). The trade-in
     * credit and the price-difference settlement are applied afterwards.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: Invoice, 1: float}
     */
    private function createExchangeInvoice(Request $request, Invoice $original, ReturnExchange $returnExchange, array $validated, ?Shift $openShift): array
    {
        $businessId = $request->user()->business_id;
        $userId = $request->user()->id;

        $exchangeNumber = DocumentNumberService::nextFor(
            $original->business->settings ?? [],
            'invoice',
            $businessId
        );

        $invoice = Invoice::create([
            'business_id' => $businessId,
            'user_id' => $userId,
            'shift_id' => $openShift?->id,
            'customer_id' => $original->customer_id,
            'invoice_number' => $exchangeNumber,
            'status' => 'completed',
            'payment_status' => 'paid',
            'currency' => $original->currency ?? 'JOD',
            'notes' => 'Exchange for '.$original->invoice_number,
            'metadata' => ['exchange' => true, 'return_exchange_id' => $returnExchange->id],
        ]);

        $subtotal = 0.0;
        $totalTax = 0.0;

        $warehouseId = Warehouse::where('business_id', $businessId)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        foreach ($validated['exchange_items'] as $item) {
            $product = Product::findOrFail($item['product_id']);
            $qty = (float) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];
            $taxRate = (float) ($item['tax_rate'] ?? $product->tax_rate ?? 0);
            $lineTotal = round($qty * $unitPrice, 2);
            $lineTax = round(TaxCalculator::line($lineTotal, $taxRate, false)['tax'], 2);

            $subtotal += $lineTotal;
            $totalTax += $lineTax;

            $invoiceItem = InvoiceItem::create([
                'business_id' => $businessId,
                'invoice_id' => $invoice->id,
                'product_id' => $product->id,
                'name' => $product->name,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'discount' => 0,
                'tax_rate' => $taxRate,
                'tax_amount' => $lineTax,
                'total' => round($lineTotal + $lineTax, 2),
            ]);

            $deductions = StockService::deductForSale($product, $qty, $businessId);
            if ($product->has_batch) {
                $invoiceItem->update(['metadata' => ['deductions' => $deductions]]);
            }

            StockMovement::create([
                'business_id' => $businessId,
                'product_id' => $product->id,
                'from_warehouse_id' => $warehouseId,
                'quantity' => $qty,
                'type' => 'reduction',
                'reference_type' => 'exchange_sale',
                'reference_id' => $returnExchange->id,
                'notes' => 'Exchange sale - '.$exchangeNumber,
            ]);
        }

        $net = round($subtotal + $totalTax, 2);

        $invoice->update([
            'subtotal' => $subtotal,
            'total_amount' => $subtotal,
            'tax_amount' => $totalTax,
            'discount_amount' => 0,
            'net_amount' => $net,
        ]);

        app(AccountingService::class)->postSaleEntry($businessId, $invoice, $userId);

        if ($openShift) {
            $openShift->increment('total_sales', $net);
            $openShift->increment('total_transactions');
        }

        return [$invoice, $net];
    }

    /**
     * Settle the money side of an exchange. The trade-in credit absorbs the
     * returned value onto the new invoice; a positive difference is collected
     * as a payment, a negative difference is refunded back to the customer.
     */
    private function settleExchangeDifference(Request $request, ReturnExchange $returnExchange, Invoice $exchangeInvoice, float $difference, ?Shift $openShift): void
    {
        $businessId = $request->user()->business_id;
        $userId = $request->user()->id;
        $accounting = app(AccountingService::class);

        $creditPayment = Payment::create([
            'business_id' => $businessId,
            'user_id' => $userId,
            'invoice_id' => $exchangeInvoice->id,
            'shift_id' => $openShift?->id,
            'customer_id' => $exchangeInvoice->customer_id,
            'payment_number' => 'PAY-'.strtoupper(Str::random(8)),
            'amount' => round((float) $returnExchange->returned_amount, 2),
            'method' => 'credit',
            'status' => 'completed',
            'metadata' => ['exchange_trade_in' => true, 'return_exchange_id' => $returnExchange->id],
        ]);
        $accounting->postExchangeCreditEntry($businessId, $creditPayment, $userId);

        if ($difference > 0.009) {
            $method = $request->input('exchange_difference_method') ?? 'cash';
            $diffPayment = Payment::create([
                'business_id' => $businessId,
                'user_id' => $userId,
                'invoice_id' => $exchangeInvoice->id,
                'shift_id' => $openShift?->id,
                'customer_id' => $exchangeInvoice->customer_id,
                'payment_number' => 'PAY-'.strtoupper(Str::random(8)),
                'amount' => round($difference, 2),
                'method' => $method,
                'status' => 'completed',
            ]);
            $accounting->postInvoicePaymentEntry($businessId, $diffPayment, $userId);

            if ($openShift) {
                $breakdown = $openShift->payment_breakdown ?? [];
                $breakdown[$method] = ($breakdown[$method] ?? 0) + round($difference, 2);
                $openShift->update(['payment_breakdown' => $breakdown]);
            }
        } elseif ($difference < -0.009) {
            $accounting->postExchangeSurplusEntry($businessId, $returnExchange, round(-$difference, 2), $userId);
        }

        $exchangeInvoice->recalculatePaymentStatus();
    }
}
