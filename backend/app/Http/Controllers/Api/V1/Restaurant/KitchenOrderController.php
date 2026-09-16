<?php

namespace App\Http\Controllers\Api\V1\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\KitchenOrder;
use App\Models\KitchenOrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KitchenOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = KitchenOrder::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('order_number', 'ilike', "%{$search}%");
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('station')) {
            $query->where('station', $request->input('station'));
        }

        if ($request->has('order_type')) {
            $query->where('order_type', $request->input('order_type'));
        }

        $orders = $query->with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($orders);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_number' => 'nullable|string',
            'table_number' => 'nullable|string',
            'order_type' => 'required|in:dine_in,takeaway,delivery',
            'priority' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
            'station' => 'nullable|string',
            'metadata' => 'nullable|array',
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.name' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.special_instructions' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $order = KitchenOrder::create([
                'business_id' => $request->user()->business_id,
                'order_number' => $validated['order_number'] ?? 'KO-' . strtoupper(Str::random(8)),
                'table_number' => $validated['table_number'] ?? null,
                'order_type' => $validated['order_type'],
                'priority' => $validated['priority'] ?? 0,
                'status' => 'new',
                'notes' => $validated['notes'] ?? null,
                'station' => $validated['station'] ?? null,
                'metadata' => $validated['metadata'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                KitchenOrderItem::create([
                    'business_id' => $request->user()->business_id,
                    'kitchen_order_id' => $order->id,
                    'product_id' => $item['product_id'],
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'special_instructions' => $item['special_instructions'] ?? null,
                ]);
            }

            $order->load('items');

            return response()->json($order, 201);
        });
    }

    public function show(KitchenOrder $kitchenOrder): JsonResponse
    {
        $kitchenOrder->load('items');

        return response()->json($kitchenOrder);
    }

    public function update(Request $request, KitchenOrder $kitchenOrder): JsonResponse
    {
        $validated = $request->validate([
            'order_number' => 'nullable|string',
            'table_number' => 'nullable|string',
            'order_type' => 'sometimes|in:dine_in,takeaway,delivery',
            'priority' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
            'station' => 'nullable|string',
            'metadata' => 'nullable|array',
            'items' => 'nullable|array',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.name' => 'required_with:items|string',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.special_instructions' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $kitchenOrder) {
            $orderFields = collect($validated)->except('items')->filter()->toArray();
            if (!empty($orderFields)) {
                $kitchenOrder->update($orderFields);
            }

            if (isset($validated['items'])) {
                $kitchenOrder->items()->delete();

                foreach ($validated['items'] as $item) {
                    KitchenOrderItem::create([
                        'business_id' => $kitchenOrder->business_id,
                        'kitchen_order_id' => $kitchenOrder->id,
                        'product_id' => $item['product_id'],
                        'name' => $item['name'],
                        'quantity' => $item['quantity'],
                        'special_instructions' => $item['special_instructions'] ?? null,
                    ]);
                }
            }

            $kitchenOrder->load('items');

            return response()->json($kitchenOrder);
        });
    }

    public function updateStatus(Request $request, KitchenOrder $kitchenOrder): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:new,preparing,ready,served,cancelled',
        ]);

        $kitchenOrder->update([
            'status' => $validated['status'],
            'completed_at' => in_array($validated['status'], ['served', 'cancelled']) ? now() : $kitchenOrder->completed_at,
        ]);

        return response()->json($kitchenOrder);
    }

    public function destroy(KitchenOrder $kitchenOrder): JsonResponse
    {
        $kitchenOrder->items()->delete();
        $kitchenOrder->delete();

        return response()->json(['message' => 'Kitchen order deleted.']);
    }
}
