<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Rules\ValidPhone;
use App\Services\PhoneNormalizer;
use App\Services\SupplierLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Supplier::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('contact_name', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $suppliers = $query->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        $suppliers->getCollection()->transform(function (Supplier $supplier) {
            $supplier->setAttribute('balance', $this->balanceOf($supplier));

            return $supplier;
        });

        return response()->json($suppliers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'contact_name' => 'nullable|string',
            'email' => 'nullable|email:rfc',
            'phone' => ['nullable', new ValidPhone],
            'tax_number' => 'nullable|string',
            'address' => 'nullable|string',
            'payment_terms' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['is_active'] = $validated['is_active'] ?? true;
        if (! empty($validated['phone'])) {
            $validated['phone'] = PhoneNormalizer::normalize($validated['phone']);
        }

        $supplier = Supplier::create($validated);

        return response()->json($supplier, 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $supplier->setAttribute('balance', $this->balanceOf($supplier));

        return response()->json($supplier);
    }

    /**
     * Current payable balance for a supplier: credit-method goods receipts
     * (direct AND PO-linked) minus completed settlement payments and
     * purchase-return debit notes. Positive = owed to the supplier,
     * negative = prepayment paid before goods arrived. Approving a purchase
     * order has no effect on the balance â€” AP is recognized only on receipt.
     */
    private function balanceOf(Supplier $supplier): float
    {
        return SupplierLedgerService::balanceFor($supplier->id);
    }

    /**
     * Chronological supplier ledger: credit-method goods receipts (Cr)
     * increase the payable, completed settlement payments (Dr) and purchase
     * returns (Dr) reduce it, with a running balance. PO approvals are not
     * ledger activity.
     */
    public function ledger(Request $request, Supplier $supplier): JsonResponse
    {
        if ((string) $supplier->business_id !== (string) $request->user()->business_id) {
            return response()->json(['message' => 'Supplier not found.'], 404);
        }

        $rows = [];

        foreach ($supplier->goodsReceipts()
            ->whereRaw('(total_amount - COALESCE(pay_now_amount, 0)) > 0.005')
            ->orderBy('created_at')
            ->get() as $grn) {
            $rows[] = [
                'kind' => 'goods_receipt',
                'date' => $grn->received_at?->toDateString() ?? $grn->created_at->toDateString(),
                'reference' => $grn->receipt_number,
                'detail' => $grn->purchase_order_id
                    ? (($grn->pay_now_amount ?? 0) > 0
                        ? 'Goods received (partially paid at door)'
                        : 'Goods received (credit)')
                    : ($grn->reference_invoice_number
                        ? 'Direct goods receipt ('.$grn->reference_invoice_number.')'
                        : 'Direct goods receipt (credit)'),
                'debit' => 0,
                'credit' => round((float) $grn->payable_credit, 2),
                '_ts' => $grn->created_at->format('Y-m-d H:i:s.u'),
            ];
        }

        foreach ($supplier->purchasePayments()->where('status', 'completed')->orderBy('created_at')->get() as $payment) {
            if (($payment->metadata['source'] ?? null) === 'goods_receipt_direct') {
                continue;
            }

            $rows[] = [
                'kind' => 'payment',
                'date' => $payment->created_at->toDateString(),
                'reference' => $payment->payment_number,
                'detail' => 'Payment ('.ucfirst(str_replace('_', ' ', $payment->method)).')',
                'debit' => round((float) $payment->amount, 2),
                'credit' => 0,
                '_ts' => $payment->created_at->format('Y-m-d H:i:s.u'),
            ];
        }

        SupplierLedgerService::supplierLiabilityAdjustmentsQuery()
            ->orderBy('created_at')
            ->get(['created_at', 'adjustment_number', 'type', 'unit_cost', 'quantity_adjusted', 'metadata'])
            ->each(function (InventoryAdjustment $adjustment) use ($supplier, &$rows) {
                if ((string) ($adjustment->metadata['supplier_id'] ?? '') === (string) $supplier->id) {
                    $isReturn = $adjustment->type === 'purchase_return';
                    $rows[] = [
                        'kind' => $isReturn ? 'purchase_return' : 'supplier_claim',
                        'date' => $adjustment->created_at->toDateString(),
                        'reference' => $adjustment->adjustment_number,
                        'detail' => $isReturn
                            ? 'Purchase return / credit note'
                            : 'Debit note (supplier '.$adjustment->type.' claim)',
                        'debit' => round(abs((float) $adjustment->quantity_adjusted) * (float) ($adjustment->unit_cost ?? 0), 2),
                        'credit' => 0,
                        '_ts' => $adjustment->created_at->format('Y-m-d H:i:s.u'),
                    ];
                }
            });

        usort($rows, fn ($a, $b) => strcmp($a['_ts'].($a['reference'] ?? ''), $b['_ts'].($b['reference'] ?? '')));

        $running = 0;
        foreach ($rows as &$row) {
            $running = round($running + (float) $row['credit'] - (float) $row['debit'], 2);
            $row['balance'] = $running;
            unset($row['_ts']);
        }

        return response()->json([
            'supplier' => ['id' => $supplier->id, 'name' => $supplier->name, 'phone' => $supplier->phone],
            'balance' => $this->balanceOf($supplier),
            'rows' => array_values($rows),
        ]);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'contact_name' => 'nullable|string',
            'email' => 'nullable|email:rfc',
            'phone' => ['nullable', new ValidPhone],
            'tax_number' => 'nullable|string',
            'address' => 'nullable|string',
            'payment_terms' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        if (array_key_exists('phone', $validated) && ! empty($validated['phone'])) {
            $validated['phone'] = PhoneNormalizer::normalize($validated['phone']);
        }

        $supplier->update($validated);

        return response()->json($supplier);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->delete();

        return response()->json(['message' => 'Supplier deleted.']);
    }

    public function products(Request $request, Supplier $supplier): JsonResponse
    {
        if ($supplier->business_id !== $request->user()->business_id) {
            abort(404);
        }

        $items = $supplier->supplierProducts()
            ->with('product:id,name,sku,unit,is_weighable,is_active')
            ->orderBy('name')
            ->get();

        return response()->json($items);
    }

    public function addProduct(Request $request, Supplier $supplier): JsonResponse
    {
        if ($supplier->business_id !== $request->user()->business_id) {
            abort(404);
        }

        $validated = $request->validate([
            'product_id' => ['nullable', Rule::exists('products', 'id')->where('business_id', $request->user()->business_id)],
            'name' => 'nullable|string|max:255',
            'catalog_cost' => 'nullable|numeric|min:0',
        ]);

        $product = null;
        if (! empty($validated['product_id'])) {
            $product = $supplier->supplierProducts()
                ->firstWhere('product_id', $validated['product_id'])
                ?->product
                ?? Product::find($validated['product_id']);

            $validated['name'] = $validated['name'] ?? ($product?->name ?? '');
        }

        $name = $validated['name'] ?? '';
        if (trim($name) === '') {
            return response()->json(['message' => 'A product name is required.'], 422);
        }

        $item = $supplier->supplierProducts()->updateOrCreate(
            ['supplier_id' => $supplier->id, 'name' => $name],
            [
                'product_id' => $validated['product_id'] ?? null,
                'catalog_cost' => $validated['catalog_cost'] ?? null,
                'is_imported' => $product !== null,
            ],
        );

        $item->load('product:id,name,sku,unit,is_weighable,is_active');

        return response()->json($item, 201);
    }

    public function removeProduct(Request $request, Supplier $supplier, SupplierProduct $supplierProduct): JsonResponse
    {
        if ($supplier->business_id !== $request->user()->business_id) {
            abort(404);
        }

        if ($supplierProduct->supplier_id !== $supplier->id) {
            return response()->json(['message' => 'This item does not belong to the given supplier.'], 422);
        }

        $supplierProduct->delete();

        return response()->json(['message' => 'Catalog item removed.']);
    }
}
