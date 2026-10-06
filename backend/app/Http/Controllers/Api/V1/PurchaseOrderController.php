<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderPayment;
use App\Models\SupplierProduct;
use App\Rules\ProductQuantity;
use App\Services\AccountingService;
use App\Services\DocumentNumberService;
use App\Services\NotificationService;
use App\Services\ProductProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseOrder::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('order_number', 'ilike', "%{$search}%");
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $orders = $query->with('supplier:id,name')
            ->withSum(['payments as payments_sum' => fn ($q) => $q->where('status', 'completed')], 'amount')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($orders);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('business_id', $request->user()->business_id)->whereNull('deleted_at')],
            'order_number' => 'nullable|string',
            'notes' => 'nullable|string',
            'expected_delivery' => 'nullable|date',
            'items' => 'required|array',
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('business_id', $request->user()->business_id)->whereNull('deleted_at')],
            'items.*.name' => 'required|string',
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', ProductQuantity::forItems(fn (string $attribute) => $request->input(str_replace('.quantity', '.product_id', $attribute)))],
            'items.*.unit_cost' => 'required|numeric|min:0',
            'items.*.import_product' => 'nullable|boolean',
        ]);

        foreach ($validated['items'] as $index => $item) {
            if (empty($item['product_id']) && empty($item['import_product'])) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'Select an existing product or allow importing this item as a new product.',
                ]);
            }
        }

        return DB::transaction(function () use ($request, $validated) {
            $totalAmount = collect($validated['items'])->sum(fn ($item) => $item['quantity'] * $item['unit_cost']);

            $order = PurchaseOrder::create([
                'business_id' => $request->user()->business_id,
                'supplier_id' => $validated['supplier_id'] ?? null,
                'order_number' => $validated['order_number'] ?? DocumentNumberService::nextFor(
                    $request->user()->business->settings ?? [],
                    'purchase_order',
                    $request->user()->business_id
                ),
                'total_amount' => $totalAmount,
                'status' => 'draft',
                'notes' => $validated['notes'] ?? null,
                'expected_delivery' => $validated['expected_delivery'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                if ($productId <= 0) {
                    $productId = $this->importProduct($request, $item)->id;
                }

                PurchaseOrderItem::create([
                    'business_id' => $request->user()->business_id,
                    'purchase_order_id' => $order->id,
                    'product_id' => $productId,
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total' => $item['quantity'] * $item['unit_cost'],
                ]);

                if (! empty($validated['supplier_id'])) {
                    SupplierProduct::updateOrCreate(
                        ['business_id' => $request->user()->business_id, 'supplier_id' => $validated['supplier_id'], 'name' => $item['name']],
                        ['product_id' => $productId, 'catalog_cost' => $item['unit_cost'], 'is_imported' => true],
                    );
                }
            }

            $order->load('items', 'supplier');

            return response()->json($order, 201);
        });
    }

    protected function importProduct(Request $request, array $item): Product
    {
        return ProductProvisioner::create([
            'business_id' => $request->user()->business_id,
            'user_id' => $request->user()->id,
            'name' => $item['name'],
            'unit_cost' => $item['unit_cost'],
            'supplier_id' => $request->input('supplier_id'),
            'source' => 'po_import',
            'has_batch' => true,
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load('items', 'supplier', 'payments');

        return response()->json($purchaseOrder);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'order_number' => 'nullable|string',
            'status' => 'nullable|string|in:draft,ordered,partially_received,received,cancelled',
            'notes' => 'nullable|string',
            'expected_delivery' => 'nullable|date',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.name' => 'required_with:items|string',
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.01', ProductQuantity::forItems(fn (string $attribute) => $request->input(str_replace('.quantity', '.product_id', $attribute)))],
            'items.*.unit_cost' => 'required_with:items|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $validated, $purchaseOrder) {
            if (isset($validated['status'])) {
                $allowedTransitions = [
                    'draft' => ['ordered', 'cancelled'],
                    'ordered' => ['partially_received', 'received', 'cancelled'],
                    'partially_received' => ['received', 'cancelled'],
                    'received' => [],
                    'cancelled' => [],
                ];

                if (! in_array($validated['status'], $allowedTransitions[$purchaseOrder->status] ?? [], true)) {
                    return response()->json([
                        'message' => "Cannot transition from '{$purchaseOrder->status}' to '{$validated['status']}'.",
                    ], 422);
                }
            }

            if (isset($validated['items']) && $purchaseOrder->status !== 'draft') {
                return response()->json([
                    'message' => 'Purchase order items can only be edited while the order is a draft.',
                ], 422);
            }

            $orderFields = collect($validated)->except('items')->filter()->toArray();

            // "Placed" is approval, not creation: store() always makes a draft
            // (no commitment yet), and `draft -> ordered` is the only transition
            // into ordered — so this fires exactly once per purchase order.
            $justOrdered = false;

            if (! empty($orderFields)) {
                $purchaseOrder->update($orderFields);

                $justOrdered = $purchaseOrder->wasChanged('status')
                    && $purchaseOrder->status === 'ordered';
            }

            if (isset($validated['items'])) {
                $purchaseOrder->items()->delete();

                foreach ($validated['items'] as $item) {
                    PurchaseOrderItem::create([
                        'business_id' => $request->user()->business_id,
                        'purchase_order_id' => $purchaseOrder->id,
                        'product_id' => $item['product_id'],
                        'name' => $item['name'],
                        'quantity' => $item['quantity'],
                        'unit_cost' => $item['unit_cost'],
                        'total' => $item['quantity'] * $item['unit_cost'],
                    ]);
                }

                $totalAmount = collect($validated['items'])->sum(fn ($item) => $item['quantity'] * $item['unit_cost']);
                $purchaseOrder->update(['total_amount' => $totalAmount]);
            }

            $purchaseOrder->load('items', 'supplier');

            if ($justOrdered) {
                app(NotificationService::class)->purchaseOrderPlaced($purchaseOrder);
            }

            return response()->json($purchaseOrder);
        });
    }

    public function destroy(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->items()->delete();
        $purchaseOrder->delete();

        return response()->json(['message' => 'Purchase order deleted.']);
    }

    public function pay(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if ((string) $purchaseOrder->business_id !== (string) $request->user()->business_id) {
            return response()->json(['message' => 'Purchase order not found.'], 404);
        }

        if ($purchaseOrder->status === 'draft') {
            return response()->json(['message' => 'Payments cannot be recorded for draft orders. Please approve the order first.'], 422);
        }

        if ($purchaseOrder->status === 'cancelled') {
            return response()->json(['message' => 'Cannot record a payment on a cancelled purchase order.'], 422);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'method' => 'required|string|in:cash,card,bank_transfer,check,mobile',
            'reference_number' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $paidAmount = (float) $purchaseOrder->payments()->where('status', 'completed')->sum('amount');
        $remaining = (float) $purchaseOrder->total_amount - $paidAmount;

        if ($validated['amount'] > $remaining + 0.01) {
            return response()->json([
                'message' => "Payment amount ({$validated['amount']}) exceeds remaining balance ({$remaining}).",
            ], 422);
        }

        return DB::transaction(function () use ($request, $validated, $purchaseOrder) {
            $payment = PurchaseOrderPayment::create([
                'business_id' => $request->user()->business_id,
                'user_id' => $request->user()->id,
                'purchase_order_id' => $purchaseOrder->id,
                'supplier_id' => $purchaseOrder->supplier_id,
                'payment_number' => 'POPAY-'.strtoupper(Str::random(8)),
                'amount' => $validated['amount'],
                'method' => $validated['method'],
                'reference_number' => $validated['reference_number'] ?? null,
                'status' => 'completed',
                'notes' => $validated['notes'] ?? null,
            ]);

            $payment->load(['purchaseOrder:id,order_number', 'supplier:id,name', 'user:id,name']);

            app(AccountingService::class)->postSupplierPaymentEntry($request->user()->business_id, $payment, $request->user()->id);

            return response()->json($payment, 201);
        });
    }
}
