<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Rules\ProductQuantity;
use App\Services\AccountingService;
use App\Services\DocumentNumberService;
use App\Services\InvoiceService;
use App\Services\LoyaltyService;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('invoice_number', 'ilike', "%{$search}%");
        }

        if ($request->has('payment_status')) {
            $query->where('payment_status', $request->input('payment_status'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->has('from_date')) {
            $query->where('created_at', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('created_at', '<=', $request->input('to_date'));
        }

        $invoices = $query->with(['items', 'customer:id,name', 'user:id,name', 'payments:id,amount,method,status'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($invoices);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'due_date' => 'nullable|date',
            'currency' => 'nullable|string|max:3',
            'shipping_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'status' => 'nullable|string|in:draft,sent,void,paid,completed',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.name' => 'required|string',
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', ProductQuantity::forItems(fn (string $attribute) => $request->input(str_replace('.quantity', '.product_id', $attribute)))],
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string|in:cash,card,bank_transfer,check,mobile,split,credit',
            'payment_status' => 'nullable|string|in:paid,unpaid,partial',
            'split_details' => 'nullable|array',
            'split_details.*.method' => 'required|string|in:cash,card',
            'split_details.*.amount' => 'required|numeric|min:0',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'discount_amount' => 'nullable|numeric|min:0',
            'promotions' => 'nullable|array',
            'promotions.*.id' => ['required', 'integer', Rule::exists('promotions', 'id')->where('business_id', $request->user()->business_id)],
            'promotions.*.discount' => 'nullable|numeric|min:0',
        ]);

        $paymentMethod = $validated['payment_method'] ?? null;
        if ($paymentMethod === 'split' || $paymentMethod === 'credit') {
            $business = $request->user()->business;
            $settings = $business->settings ?? [];
            if ($paymentMethod === 'split' && empty($settings['allow_split_payments'])) {
                return response()->json(['message' => 'Split payments are not enabled for this business.'], 422);
            }
            if ($paymentMethod === 'credit' && empty($settings['allow_credit_sales'])) {
                return response()->json(['message' => 'Credit sales are not enabled for this business.'], 422);
            }
            if ($paymentMethod === 'credit' && empty($validated['customer_id'])) {
                return response()->json(['message' => 'A registered customer is required for credit payments.'], 422);
            }
        }

        try {
            return DocumentNumberService::retryOnConflict(function () use ($request, $validated) {
                return DB::transaction(function () use ($request, $validated) {
                    $invoiceNumber = DocumentNumberService::nextFor(
                        $request->user()->business->settings ?? [],
                        'invoice',
                        $request->user()->business_id
                    );

                    $paymentMethod = $validated['payment_method'] ?? null;
                    $isPosPayment = $paymentMethod !== null;

                    $openShift = Shift::openFor($request->user()->business_id, $request->user()->id);

                    $isCreditPayment = $paymentMethod === 'credit';
                    $invoice = Invoice::create([
                        'business_id' => $request->user()->business_id,
                        'user_id' => $request->user()->id,
                        'shift_id' => $openShift?->id,
                        'customer_id' => $validated['customer_id'] ?? null,
                        'invoice_number' => $invoiceNumber,
                        'status' => $isPosPayment ? ($isCreditPayment ? 'completed' : 'paid') : ($validated['status'] ?? 'draft'),
                        'due_date' => $validated['due_date'] ?? null,
                        'currency' => $validated['currency'] ?? 'JOD',
                        'shipping_amount' => $validated['shipping_amount'] ?? 0,
                        'notes' => $validated['notes'] ?? null,
                        'payment_status' => $isPosPayment ? ($isCreditPayment ? 'unpaid' : 'paid') : ($validated['payment_status'] ?? 'unpaid'),
                    ]);

                    $businessSettings = $request->user()->business->mergedSettings();
                    $taxMethod = $businessSettings['tax_calculation_method'] ?? 'exclusive';
                    $taxEnabled = ($businessSettings['tax_enabled'] ?? true) && $taxMethod !== 'exclusive';
                    $allowNegativeStock = (bool) ($businessSettings['allow_negative_stock'] ?? false);

                    $defaultWarehouseId = $validated['warehouse_id'] ?? Warehouse::where('business_id', $request->user()->business_id)
                        ->where('is_active', true)
                        ->orderBy('id')
                        ->value('id');

                    $totals = app(InvoiceService::class)->createItems($invoice, $validated['items'], $taxEnabled);
                    $subtotal = $totals['subtotal'];
                    $totalTax = $totals['tax'];
                    $totalDiscount = $totals['discount'];

                    foreach ($validated['items'] as $item) {
                        if (! empty($item['product_id'])) {
                            $product = Product::findOrFail($item['product_id']);
                            $deductNow = ($validated['payment_status'] ?? 'unpaid') !== 'unpaid' || $paymentMethod !== null;
                            if ($deductNow) {
                                $businessId = $request->user()->business_id;
                                $deductions = StockService::deductForSale($product, (float) $item['quantity'], $businessId, $allowNegativeStock);

                                if ($product->has_batch) {
                                    $invoiceItem = InvoiceItem::where('invoice_id', $invoice->id)
                                        ->where('product_id', $item['product_id'])
                                        ->latest('id')
                                        ->first();
                                    if ($invoiceItem) {
                                        $invoiceItem->update([
                                            'metadata' => array_merge($invoiceItem->metadata ?? [], ['deductions' => $deductions]),
                                        ]);
                                    }
                                }

                                StockMovement::create([
                                    'business_id' => $request->user()->business_id,
                                    'product_id' => $item['product_id'],
                                    'from_warehouse_id' => $defaultWarehouseId,
                                    'quantity' => $item['quantity'],
                                    'type' => 'reduction',
                                    'reference_type' => 'invoice',
                                    'reference_id' => $invoice->id,
                                    'notes' => 'Sale - '.$invoiceNumber,
                                ]);
                            }
                        }
                    }

                    $shipping = (float) ($validated['shipping_amount'] ?? 0);
                    $requestDiscount = (float) ($validated['discount_amount'] ?? 0);
                    $totalDiscount += $requestDiscount;

                    $invoice->update([
                        'subtotal' => $subtotal,
                        'total_amount' => $subtotal,
                        'tax_amount' => round($totalTax, 2),
                        'discount_amount' => $totalDiscount,
                        'net_amount' => $taxEnabled
                            ? round($subtotal - $totalDiscount + $shipping, 2)
                            : round($subtotal + $totalTax - $totalDiscount + $shipping, 2),
                    ]);

                    $invoiceService = app(InvoiceService::class);
                    $invoiceService->recordPromotionUsage($invoice, $validated['promotions'] ?? []);

                    $createdPayments = [];

                    if ($paymentMethod === 'split') {
                        $invoice->update(['payment_status' => 'paid']);
                        foreach ($validated['split_details'] ?? [] as $split) {
                            $createdPayments[] = Payment::create([
                                'business_id' => $request->user()->business_id,
                                'invoice_id' => $invoice->id,
                                'shift_id' => $openShift?->id,
                                'user_id' => $request->user()->id,
                                'customer_id' => $validated['customer_id'] ?? null,
                                'payment_number' => 'PAY-'.strtoupper(Str::random(8)),
                                'amount' => $split['amount'],
                                'method' => $split['method'],
                                'status' => 'completed',
                            ]);
                        }
                    } elseif ($paymentMethod === 'credit') {
                        $invoice->update(['payment_status' => 'unpaid']);
                    } elseif ($isPosPayment) {
                        $createdPayments[] = Payment::create([
                            'business_id' => $request->user()->business_id,
                            'invoice_id' => $invoice->id,
                            'shift_id' => $openShift?->id,
                            'user_id' => $request->user()->id,
                            'customer_id' => $validated['customer_id'] ?? null,
                            'payment_number' => 'PAY-'.strtoupper(Str::random(8)),
                            'amount' => $invoice->net_amount,
                            'method' => $paymentMethod,
                            'status' => 'completed',
                        ]);
                    }

                    // Manual (back-office) invoice created as "paid" without a
                    // payment method: record an automatic cash payment against the
                    // safe so the 1040 receivable is cleared.
                    if (! $isPosPayment && $invoice->payment_status === 'paid' && empty($createdPayments)) {
                        $createdPayments[] = Payment::create([
                            'business_id' => $request->user()->business_id,
                            'invoice_id' => $invoice->id,
                            'shift_id' => $openShift?->id,
                            'user_id' => $request->user()->id,
                            'customer_id' => $validated['customer_id'] ?? null,
                            'payment_number' => 'PAY-'.strtoupper(Str::random(8)),
                            'amount' => $invoice->net_amount,
                            'method' => 'cash',
                            'status' => 'completed',
                            'metadata' => ['auto_status_payment' => true],
                        ]);
                    }

                    if ($openShift && $paymentMethod !== 'credit') {
                        $openShift->increment('total_sales', $invoice->net_amount);
                        $openShift->increment('total_transactions');
                        $openShift->increment('total_discounts', $totalDiscount);

                        $breakdown = $openShift->payment_breakdown ?? [];
                        if ($paymentMethod === 'split') {
                            foreach ($validated['split_details'] ?? [] as $split) {
                                $breakdown[$split['method']] = ($breakdown[$split['method']] ?? 0) + (float) $split['amount'];
                            }
                        } else {
                            $breakdown[$paymentMethod] = ($breakdown[$paymentMethod] ?? 0) + (float) $invoice->net_amount;
                        }
                        $openShift->update(['payment_breakdown' => $breakdown]);
                    }

                    $invoice->load('items', 'customer', 'payments');

                    $accounting = app(AccountingService::class);
                    if ($invoice->status !== 'void') {
                        $accounting->postSaleEntry($invoice->business_id, $invoice, $request->user()->id);
                    }
                    foreach ($createdPayments as $payment) {
                        $accounting->postInvoicePaymentEntry($invoice->business_id, $payment, $request->user()->id);
                    }

                    if (config('features.crm_enabled')) {
                        app(LoyaltyService::class)->earnForInvoice($invoice);
                    }

                    return response()->json($invoice, 201);
                });
            }, 'invoices');
        } catch (\Throwable $e) {
            Log::error('POS checkout failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user()->id,
                'business_id' => $request->user()->business_id,
            ]);
            $statusCode = $e instanceof \RuntimeException ? 422 : 500;

            return response()->json(['message' => $e->getMessage()], $statusCode);
        }
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load('items', 'user:id,name', 'customer:id,name,email,phone', 'payments');

        return response()->json($invoice);
    }

    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status === 'void') {
            return response()->json(['message' => 'Cannot update a voided invoice.'], 422);
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'status' => 'sometimes|string|in:draft,sent',
            'payment_status' => 'sometimes|string|in:paid,unpaid,partial,void',
            'due_date' => 'nullable|date',
            'currency' => 'nullable|string|max:3',
            'shipping_amount' => 'sometimes|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.product_id' => 'nullable|exists:products,id',
            'items.*.name' => 'required_with:items|string',
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.01', ProductQuantity::forItems(fn (string $attribute) => $request->input(str_replace('.quantity', '.product_id', $attribute)))],
            'items.*.unit_price' => 'required_with:items|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'void') {
            return response()->json([
                'message' => 'Use the dedicated void endpoint to void an invoice (it reverses payments and restores stock).',
            ], 422);
        }

        return DB::transaction(function () use ($request, $validated, $invoice) {
            if (isset($validated['status']) && $validated['status'] === 'sent' && ! $invoice->sent_at) {
                $validated['sent_at'] = now();
            }

            $invoiceFields = collect($validated)->except('items', 'payment_status')->filter()->toArray();
            if (! empty($invoiceFields)) {
                $invoice->update($invoiceFields);
            }

            if (isset($validated['items'])) {
                $invoice->items()->delete();

                $businessSettings = $request->user()->business->mergedSettings();
                $taxMethod = $businessSettings['tax_calculation_method'] ?? 'exclusive';
                $taxEnabled = ($businessSettings['tax_enabled'] ?? true) && $taxMethod !== 'exclusive';

                $totals = app(InvoiceService::class)->createItems($invoice, $validated['items'], $taxEnabled);
                $subtotal = $totals['subtotal'];
                $totalTax = $totals['tax'];
                $totalDiscount = $totals['discount'];

                $shipping = (float) ($validated['shipping_amount'] ?? $invoice->shipping_amount ?? 0);
                $requestDiscount = (float) ($validated['discount_amount'] ?? $invoice->discount_amount ?? 0);
                $totalDiscount += $requestDiscount;

                $invoice->update([
                    'subtotal' => $subtotal,
                    'total_amount' => $subtotal,
                    'tax_amount' => round($totalTax, 2),
                    'discount_amount' => $totalDiscount,
                    'net_amount' => $taxEnabled
                        ? round($subtotal - $totalDiscount + $shipping, 2)
                        : round($subtotal + $totalTax - $totalDiscount + $shipping, 2),
                ]);
            }

            $invoice->load('items', 'customer', 'payments');

            $accounting = app(AccountingService::class);
            $editedItems = isset($validated['items']);
            if ($editedItems && $accounting->hasEntry($invoice->business_id, 'sale', $invoice->id)) {
                // Items changed on an already-recognized invoice: reverse the
                // old sale entry exactly and post a fresh one (COGS may differ).
                $accounting->repostSaleEntry($invoice->business_id, $invoice, $request->user()->id);
            } elseif ($invoice->status !== 'draft') {
                $accounting->postSaleEntry($invoice->business_id, $invoice, $request->user()->id);
            }

            // Recompute payment_status from actual completed payments so edits
            // can never drift the status away from the GL/AR truth.
            $invoice->recalculatePaymentStatus();

            return response()->json($invoice);
        });
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        if ($invoice->payments()->exists()) {
            return response()->json(['message' => 'Cannot delete invoice with recorded payments.'], 422);
        }

        return DB::transaction(function () use ($invoice) {
            $accounting = app(AccountingService::class);
            if ($accounting->hasEntry($invoice->business_id, 'sale', $invoice->id)) {
                $accounting->postVoidInvoice($invoice->business_id, $invoice);
            }

            $invoice->items()->delete();
            PromotionUsage::where('invoice_id', $invoice->id)->delete();
            $invoice->delete();

            return response()->json(['message' => 'Invoice deleted.']);
        });
    }

    public function pay(Request $request, Invoice $invoice): JsonResponse
    {
        if ((string) $invoice->business_id !== (string) $request->user()->business_id) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        if ($invoice->status === 'void') {
            return response()->json(['message' => 'Cannot record payment for a voided invoice.'], 422);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string|in:cash,card,bank_transfer,check,mobile',
            'reference_number' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $netAmount = (float) $invoice->net_amount;
        $existingPaid = (float) $invoice->payments()->where('status', 'completed')->sum('amount');
        $remaining = $netAmount - $existingPaid;

        if ($validated['amount'] > $remaining + 0.01) {
            return response()->json([
                'message' => "Payment amount ({$validated['amount']}) exceeds remaining balance ({$remaining}).",
            ], 422);
        }

        return DB::transaction(function () use ($request, $validated, $invoice) {
            $paymentNumber = 'PAY-'.strtoupper(Str::random(8));

            $openShift = Shift::openFor($request->user()->business_id, $request->user()->id);

            $payment = Payment::create([
                'business_id' => $request->user()->business_id,
                'user_id' => $request->user()->id,
                'invoice_id' => $invoice->id,
                'shift_id' => $openShift?->id,
                'customer_id' => $invoice->customer_id,
                'payment_number' => $paymentNumber,
                'amount' => $validated['amount'],
                'method' => $validated['method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'completed',
            ]);

            $invoice->recalculatePaymentStatus();

            $invoice->load('items', 'customer', 'payments');

            $accounting = app(AccountingService::class);
            if ($invoice->status !== 'void') {
                $accounting->postSaleEntry($invoice->business_id, $invoice, $request->user()->id);
            }
            $accounting->postInvoicePaymentEntry($invoice->business_id, $payment, $request->user()->id);

            return response()->json([
                'payment' => $payment,
                'invoice' => $invoice,
            ], 201);
        });
    }

    public function updateStatus(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status === 'void') {
            return response()->json(['message' => 'Cannot update a voided invoice.'], 422);
        }

        $validated = $request->validate([
            'payment_status' => 'required|string|in:paid,unpaid,partial',
        ]);

        return DB::transaction(function () use ($request, $validated, $invoice) {
            $businessId = $request->user()->business_id;
            $accounting = app(AccountingService::class);
            $allowNegativeStock = (bool) ($request->user()->business->mergedSettings()['allow_negative_stock'] ?? false);

            // Moving back to unpaid/partial reverses any payments that were
            // auto-created by a previous "Mark as Paid" status flip.
            if (in_array($validated['payment_status'], ['unpaid', 'partial'], true)) {
                foreach ($invoice->payments()->where('status', 'completed')->get() as $payment) {
                    if (empty($payment->metadata['auto_status_payment'])) {
                        continue;
                    }
                    if ($accounting->hasEntry($businessId, 'invoice_payment', $payment->id)) {
                        $accounting->postPaymentRefund($businessId, $payment, (float) $payment->amount, $request->user()->id);
                    }
                    $payment->update([
                        'status' => 'refunded',
                        'refunded_amount' => $payment->amount,
                    ]);
                }
            }

            $invoice->update(['payment_status' => $validated['payment_status']]);

            // Deduct stock first (recording batch-exact FEFO deduction metadata)
            // so the recognition entry below carries the correct COGS.
            if ($validated['payment_status'] === 'paid') {
                $warehouseId = Warehouse::where('business_id', $businessId)
                    ->where('is_active', true)
                    ->orderBy('id')
                    ->value('id');

                foreach ($invoice->items as $item) {
                    if (! empty($item->product_id)) {
                        $alreadyDeducted = StockMovement::where('reference_type', 'invoice')
                            ->where('reference_id', $invoice->id)
                            ->where('product_id', $item->product_id)
                            ->where('type', 'reduction')
                            ->exists();

                        if (! $alreadyDeducted) {
                            $product = Product::findOrFail($item->product_id);
                            $deductions = StockService::deductForSale($product, (float) $item->quantity, $businessId, $allowNegativeStock);
                            if ($product->has_batch) {
                                $item->update([
                                    'metadata' => array_merge($item->metadata ?? [], ['deductions' => $deductions]),
                                ]);
                            }
                            StockMovement::create([
                                'business_id' => $businessId,
                                'product_id' => $item->product_id,
                                'from_warehouse_id' => $warehouseId,
                                'quantity' => $item->quantity,
                                'type' => 'reduction',
                                'reference_type' => 'invoice',
                                'reference_id' => $invoice->id,
                                'notes' => 'Stock deduction - status changed to paid',
                            ]);
                        }
                    }
                }
            }

            // Accrual recognition: any non-void invoice carries its receivable
            // (Dr 1040 / Cr 4010 / Cr 2020) regardless of status.
            if ($invoice->status !== 'void') {
                $accounting->postSaleEntry($businessId, $invoice, $request->user()->id);
            }

            // Clear the receivable for the remaining balance when the invoice
            // has just been marked as paid.
            if ($validated['payment_status'] === 'paid') {
                // Subsequent full payment: clear the receivable with an
                // automatic cash payment for the remaining balance.
                if ($invoice->status !== 'void') {
                    $paid = (float) $invoice->payments()->where('status', 'completed')->sum('amount');
                    $remaining = round((float) $invoice->net_amount - $paid, 2);
                    if ($remaining > 0.01) {
                        $openShift = Shift::openFor($businessId, $request->user()->id);
                        $payment = Payment::create([
                            'business_id' => $businessId,
                            'user_id' => $request->user()->id,
                            'invoice_id' => $invoice->id,
                            'shift_id' => $openShift?->id,
                            'customer_id' => $invoice->customer_id,
                            'payment_number' => 'PAY-'.strtoupper(Str::random(8)),
                            'amount' => $remaining,
                            'method' => 'cash',
                            'status' => 'completed',
                            'metadata' => ['auto_status_payment' => true],
                        ]);
                        $accounting->postInvoicePaymentEntry($businessId, $payment, $request->user()->id);
                    }
                }
            }

            $invoice->load('items', 'customer', 'payments');

            if (config('features.crm_enabled')) {
                app(LoyaltyService::class)->earnForInvoice($invoice);
            }

            return response()->json($invoice);
        });
    }

    public function void(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->status === 'void') {
            return response()->json(['message' => 'Invoice is already voided.'], 422);
        }

        return DB::transaction(function () use ($request, $invoice) {
            $businessId = $invoice->business_id;
            $accounting = app(AccountingService::class);

            // Reverse every completed payment (Dr 1040 / Cr cash) so the cash
            // side returns to zero alongside the sales reversal.
            foreach ($invoice->payments()->where('status', 'completed')->get() as $payment) {
                $remaining = round((float) $payment->amount - (float) $payment->refunded_amount, 2);
                if ($remaining <= 0) {
                    continue;
                }
                if ($accounting->hasEntry($businessId, 'invoice_payment', $payment->id)) {
                    $accounting->postPaymentRefund($businessId, $payment, $remaining, $request->user()->id);
                }
                $payment->update([
                    'status' => 'refunded',
                    'refunded_amount' => $payment->amount,
                ]);
            }

            if ($accounting->hasEntry($businessId, 'sale', $invoice->id)) {
                $accounting->postVoidInvoice($businessId, $invoice, $request->user()->id);
            }

            app(InvoiceService::class)->restoreStockForVoid($invoice, $request->user()->id);

            foreach (PromotionUsage::where('invoice_id', $invoice->id)->get() as $usage) {
                Promotion::where('id', $usage->promotion_id)->decrement('current_uses');
                $usage->delete();
            }

            $invoice->update([
                'status' => 'void',
                'voided_at' => now(),
            ]);

            return response()->json($invoice);
        });
    }

    public function duplicate(Invoice $invoice): JsonResponse
    {
        $business = $invoice->business;

        $newInvoice = DocumentNumberService::retryOnConflict(function () use ($invoice, $business) {
            return DB::transaction(function () use ($invoice, $business) {
                $created = Invoice::create([
                    'business_id' => $invoice->business_id,
                    'user_id' => $invoice->user_id,
                    'customer_id' => $invoice->customer_id,
                    'invoice_number' => DocumentNumberService::nextFor($business->settings ?? [], 'invoice', $business->id),
                    'status' => 'draft',
                    'total_amount' => $invoice->total_amount,
                    'tax_amount' => $invoice->tax_amount,
                    'discount_amount' => $invoice->discount_amount,
                    'net_amount' => $invoice->net_amount,
                    'subtotal' => $invoice->subtotal,
                    'shipping_amount' => $invoice->shipping_amount,
                    'payment_status' => 'unpaid',
                    'due_date' => $invoice->due_date,
                    'currency' => $invoice->currency,
                    'notes' => $invoice->notes,
                ]);

                foreach ($invoice->items as $item) {
                    InvoiceItem::create([
                        'business_id' => $invoice->business_id,
                        'invoice_id' => $created->id,
                        'product_id' => $item->product_id,
                        'name' => $item->name,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'discount' => $item->discount,
                        'tax_rate' => $item->tax_rate,
                        'tax_amount' => $item->tax_amount,
                        'total' => $item->total,
                    ]);
                }

                $created->load('items', 'customer');

                return $created;
            });
        }, 'invoices');

        return response()->json($newInvoice, 201);
    }
}
