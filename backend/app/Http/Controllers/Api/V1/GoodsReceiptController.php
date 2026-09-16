<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderPayment;
use App\Rules\ProductQuantity;
use App\Services\AccountingService;
use App\Services\DocumentNumberService;
use App\Services\SupplierLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GoodsReceiptController extends Controller
{
    private const PAID_METHODS = ['cash', 'card', 'bank_transfer', 'check', 'mobile'];

    public function index(Request $request): JsonResponse
    {
        $query = GoodsReceipt::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('receipt_number', 'ilike', "%{$search}%");
        }

        if ($request->has('purchase_order_id')) {
            $query->where('purchase_order_id', $request->input('purchase_order_id'));
        }

        if ($request->has('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        $receipts = $query->with(['purchaseOrder:id,order_number', 'supplier:id,name', 'user:id,name', 'items'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($receipts);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'payment_method' => 'nullable|string|in:cash,card,bank_transfer,check,mobile,credit',
            'pay_now_amount' => 'nullable|numeric|min:0',
            'received_at' => 'nullable|date',
            'reference_invoice_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.purchase_order_item_id' => 'nullable|exists:purchase_order_items,id',
            'items.*.received_quantity' => ['required', 'numeric', 'min:0.01', ProductQuantity::forItems(fn (string $attribute) => $request->input(str_replace('.received_quantity', '.product_id', $attribute)))],
            'items.*.unit_cost' => 'nullable|numeric|min:0',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.manufacturing_date' => 'nullable|date',
            'items.*.storage_location' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $businessId = $request->user()->business_id;
            $isDirect = empty($validated['purchase_order_id']);

            if ($isDirect && empty($validated['supplier_id'])) {
                throw new InsufficientStockException(
                    'A supplier must be specified when receiving goods without a purchase order.'
                );
            }

            $po = null;
            $supplierId = $validated['supplier_id'] ?? null;

            if (! $isDirect) {
                $po = PurchaseOrder::findOrFail($validated['purchase_order_id']);

                if (! in_array($po->status, ['ordered', 'partially_received'], true)) {
                    throw new InsufficientStockException(
                        "Cannot receive purchase order {$po->order_number}: only 'ordered' or 'partially received' orders can be received (currently '{$po->status}')."
                    );
                }

                $supplierId = $po->supplier_id;
            }

            $receipt = GoodsReceipt::create([
                'business_id' => $businessId,
                'user_id' => $request->user()->id,
                'purchase_order_id' => $po?->id,
                'supplier_id' => $supplierId,
                'receipt_number' => DocumentNumberService::nextFor(
                    $request->user()->business->settings ?? [],
                    'grn',
                    $businessId
                ),
                'reference_invoice_number' => $validated['reference_invoice_number'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'received_at' => ! empty($validated['received_at'])
                    ? $validated['received_at']
                    : now(),
                'payment_method' => $validated['payment_method'] ?? null,
                'status' => 'received',
            ]);

            $totalCost = 0;
            $updatedBatchProducts = [];
            $createdBatchIds = [];

            foreach ($validated['items'] as $item) {
                $product = Product::find($item['product_id']);
                if (! $product) {
                    continue;
                }

                if ($isDirect) {
                    $unitCost = isset($item['unit_cost'])
                        ? (float) $item['unit_cost']
                        : ((float) $product->cost > 0 ? (float) $product->cost : 0);
                    $factor = (float) ($product->purchase_unit_qty ?? 1);
                    $factor = $factor > 0 ? $factor : 1;
                    $baseQty = round((float) $item['received_quantity'] * $factor, 2);
                    $purchaseQty = (float) $item['received_quantity'];
                    $purchaseUnitQty = $factor;
                } else {
                    $poItem = PurchaseOrderItem::findOrFail($item['purchase_order_item_id']);

                    if ((int) $poItem->purchase_order_id !== (int) $po->id
                        || (int) $poItem->product_id !== (int) $item['product_id']) {
                        throw new InsufficientStockException(
                            "Item {$poItem->name} does not belong to purchase order {$po->order_number}."
                        );
                    }

                    if ((float) $poItem->quantity > 0
                        && (float) $poItem->received_quantity + (float) $item['received_quantity'] > (float) $poItem->quantity + 0.001) {
                        throw new InsufficientStockException(
                            "Cannot receive more than the ordered quantity for {$poItem->name}. Ordered: {$poItem->quantity}."
                        );
                    }

                    $baseQty = round((float) $item['received_quantity'], 2);
                    $unitCost = isset($item['unit_cost'])
                        ? (float) $item['unit_cost']
                        : (float) $poItem->unit_cost;
                    $purchaseQty = null;
                    $purchaseUnitQty = 1;

                    $poItem->increment('received_quantity', $baseQty);
                }

                $totalCost += $baseQty * $unitCost;

                // Persist a per-line record so receipts can be re-opened with
                // exact costs/quantities (editable unit cost â†’ WAC).
                $batch = null;
                if ($product->has_batch) {
                    if (empty($item['expiry_date'])) {
                        throw new InsufficientStockException(
                            "Expiry date is required for batch-managed product {$product->name}."
                        );
                    }

                    $batch = ProductBatch::create([
                        'business_id' => $businessId,
                        'product_id' => $product->id,
                        'batch_number' => 'GR-'.strtoupper(Str::random(8)),
                        'source_type' => 'goods_receipt',
                        'goods_receipt_id' => $receipt->id,
                        'quantity' => $baseQty,
                        'supplier_id' => $supplierId,
                        'received_date' => now()->toDateString(),
                        'expiry_date' => $item['expiry_date'],
                        'manufacturing_date' => $item['manufacturing_date'] ?? null,
                        'storage_location' => $item['storage_location'] ?? null,
                        'cost_per_unit' => round($unitCost, 2),
                        'total_cost' => round($baseQty * $unitCost, 2),
                        'is_active' => true,
                        'metadata' => ['source' => 'goods_receipt', 'receipt_id' => $receipt->id],
                    ]);
                    $updatedBatchProducts[] = $product->id;
                    $createdBatchIds[] = $batch->id;
                } else {
                    // Balance the old cost of goods on hand against the newly
                    // received value (weighted average cost for simple products).
                    $oldStock = (float) $product->stock_quantity;
                    $oldCost = (float) $product->cost;
                    $blended = $oldStock > 0
                        ? (($oldStock * $oldCost) + ($baseQty * $unitCost)) / ($oldStock + $baseQty)
                        : $unitCost;
                    $product->increment('stock_quantity', $baseQty);
                    $product->update(['cost' => round($blended, 2)]);
                    $product->autoPrice();
                }

                GoodsReceiptItem::create([
                    'business_id' => $businessId,
                    'goods_receipt_id' => $receipt->id,
                    'product_id' => $product->id,
                    'batch_id' => $batch?->id,
                    'name' => $product->name,
                    'quantity' => $baseQty,
                    'unit_cost' => round($unitCost, 4),
                    'total' => round($baseQty * $unitCost, 4),
                    'purchase_quantity' => $purchaseQty,
                    'purchase_unit_qty' => $purchaseUnitQty,
                    'expiry_date' => ! empty($item['expiry_date']) ? $item['expiry_date'] : null,
                    'storage_location' => $item['storage_location'] ?? null,
                    'metadata' => $po
                        ? ['purchase_order_id' => $po->id, 'purchase_order_item_id' => $poItem->id ?? null]
                        : ['direct' => true],
                ]);
            }

            foreach (array_unique($updatedBatchProducts) as $productId) {
                $product = Product::find($productId);
                if ($product) {
                    $product->recalculateStockQuantity();
                    $product->updateWeightedAverageCost();
                    $product->autoPrice();
                }
            }

            $totalCost = round($totalCost, 2);

            // Cash/bank paid at the door. PO advances already recorded against
            // the order (paid_amount) reduce the amount still due on this
            // receipt â€” "net due" â€” and the partially-paid portion becomes a
            // payable that the advance settles, keeping the supplier ledger at
            // zero. A receipt can never be overpaid beyond that remaining.
            $paymentMethod = $validated['payment_method'] ?? null;
            $payNow = 0.0;
            if (in_array($paymentMethod, self::PAID_METHODS, true) && $totalCost > 0.005) {
                $payNow = round(min((float) ($validated['pay_now_amount'] ?? $totalCost), $totalCost), 2);

                $maxPayNow = $po
                    ? round(max(0.0, min($totalCost, (float) $po->total_amount - (float) $po->paid_amount)), 2)
                    : $totalCost;

                if ($payNow > $maxPayNow + 0.01) {
                    throw new InsufficientStockException(
                        "Payment amount ({$payNow}) exceeds remaining balance ({$maxPayNow})."
                    );
                }
            }

            $receipt->update([
                'total_amount' => $totalCost,
                'pay_now_amount' => $payNow > 0.005 ? $payNow : null,
            ]);

            // An immediate payment (cash/bank...) either settles the recognized
            // payable (PO mode, stored on the PO) or records the cash-out for a
            // direct receipt. The GL cash/bank leg is posted once, by the goods
            // receipt entry below; the payment row exists for audit + AP=0.
            if ($payNow > 0.005) {
                PurchaseOrderPayment::create([
                    'business_id' => $businessId,
                    'user_id' => $request->user()->id,
                    'purchase_order_id' => $po?->id,
                    'goods_receipt_id' => $receipt->id,
                    'supplier_id' => $supplierId,
                    'payment_number' => 'POPAY-'.strtoupper(Str::random(8)),
                    'amount' => $payNow,
                    'method' => $paymentMethod,
                    'status' => 'completed',
                    'notes' => 'Paid directly on goods receipt '.$receipt->receipt_number,
                    'metadata' => ['source' => 'goods_receipt_direct', 'receipt_id' => $receipt->id],
                ]);
            }

            app(AccountingService::class)->postGoodsReceiptEntry(
                $businessId,
                $totalCost,
                $receipt->id,
                $request->user()->id,
                'Goods received - '.$receipt->receipt_number,
                [
                    'purchase_order_id' => $po?->id,
                    'supplier_id' => $supplierId,
                    'receipt_id' => $receipt->id,
                    'batch_ids' => $createdBatchIds,
                    'pay_now_amount' => $payNow > 0.005 ? $payNow : null,
                ],
                $payNow > 0.005 ? $paymentMethod : 'credit',
                $payNow
            );

            if (! $isDirect) {
                $allFullyReceived = $po->items()->whereColumn('received_quantity', '<', 'quantity')->doesntExist();
                if ($allFullyReceived) {
                    $po->update([
                        'status' => 'received',
                        'received_at' => now(),
                    ]);
                } else {
                    $po->update([
                        'status' => 'partially_received',
                    ]);
                }
            }

            $receipt->load(['purchaseOrder:id,order_number', 'supplier:id,name', 'user:id,name', 'items']);

            return response()->json($receipt, 201);
        });
    }

    public function show(GoodsReceipt $goodsReceipt): JsonResponse
    {
        $goodsReceipt->load(['purchaseOrder:id,order_number', 'supplier:id,name', 'user:id,name', 'items']);

        return response()->json($goodsReceipt);
    }

    /**
     * Settle the payable left open by a credit-method goods receipt
     * (payment_method = credit/null â†’ Cr 2010 was created). Direct and
     * PO-linked receipts both apply; an advance already recorded against
     * the receipt's PO reduces the remaining amount.
     */
    public function pay(Request $request, GoodsReceipt $goodsReceipt): JsonResponse
    {
        if ((string) $goodsReceipt->business_id !== (string) $request->user()->business_id) {
            return response()->json(['message' => 'Goods receipt not found.'], 404);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'nullable|string|in:cash,card,bank_transfer,check,mobile',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        // The supplier's open payable is the ceiling: payments recorded as
        // advances on the PO (before goods arrived) and same-transaction
        // cash/bank cash-outs already reduce what is still owed to this
        // supplier, so a receipt can never push it negative.
        $remaining = round(max(0.0, SupplierLedgerService::balanceFor($goodsReceipt->supplier_id)), 2);

        if ($validated['amount'] > $remaining + 0.01) {
            return response()->json([
                'message' => "Payment amount ({$validated['amount']}) exceeds remaining balance ({$remaining}).",
            ], 422);
        }

        return DB::transaction(function () use ($request, $goodsReceipt, $validated) {
            $payment = PurchaseOrderPayment::create([
                'business_id' => $request->user()->business_id,
                'user_id' => $request->user()->id,
                'purchase_order_id' => $goodsReceipt->purchase_order_id,
                'goods_receipt_id' => $goodsReceipt->id,
                'supplier_id' => $goodsReceipt->supplier_id,
                'payment_number' => 'POPAY-'.strtoupper(Str::random(8)),
                'amount' => round((float) $validated['amount'], 2),
                'method' => $validated['method'] ?? 'cash',
                'reference_number' => $validated['reference_number'] ?? null,
                'status' => 'completed',
                'notes' => $validated['notes'] ?? 'Payment for receipt '.$goodsReceipt->receipt_number,
                'metadata' => ['source' => 'goods_receipt_pay', 'receipt_id' => $goodsReceipt->id],
            ]);

            app(AccountingService::class)->postSupplierPaymentEntry(
                $request->user()->business_id,
                $payment,
                $request->user()->id
            );

            return response()->json($payment->load('goodsReceipt:id,receipt_number'), 201);
        });
    }
}
