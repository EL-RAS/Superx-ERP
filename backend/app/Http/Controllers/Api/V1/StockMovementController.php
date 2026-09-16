<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockMovement::query();

        if ($request->has('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->has('warehouse_id')) {
            $warehouseId = $request->input('warehouse_id');
            $query->where(function ($q) use ($warehouseId) {
                $q->where('from_warehouse_id', $warehouseId)
                    ->orWhere('to_warehouse_id', $warehouseId);
            });
        }

        if ($request->has('from_warehouse_id')) {
            $query->where('from_warehouse_id', $request->input('from_warehouse_id'));
        }

        if ($request->has('to_warehouse_id')) {
            $query->where('to_warehouse_id', $request->input('to_warehouse_id'));
        }

        $movements = $query->with(['product:id,name,sku', 'fromWarehouse:id,name,code', 'toWarehouse:id,name,code'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($movements);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0.01',
            'type' => 'required|string|in:addition,reduction',
            'from_warehouse_id' => 'nullable|exists:warehouses,id',
            'to_warehouse_id' => 'nullable|exists:warehouses,id',
            'reference_type' => 'nullable|string',
            'reference_id' => 'nullable|integer',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            if ($validated['type'] === 'reduction') {
                $product = Product::findOrFail($validated['product_id']);
                if ((float) $product->stock_quantity < $validated['quantity']) {
                    return response()->json([
                        'message' => "Insufficient stock. Available: {$product->stock_quantity}, requested: {$validated['quantity']}.",
                    ], 422);
                }
            }

            $movement = StockMovement::create([
                'business_id' => $request->user()->business_id,
                'product_id' => $validated['product_id'],
                'from_warehouse_id' => $validated['from_warehouse_id'] ?? null,
                'to_warehouse_id' => $validated['to_warehouse_id'] ?? null,
                'quantity' => $validated['quantity'],
                'type' => $validated['type'],
                'reference_type' => $validated['reference_type'] ?? null,
                'reference_id' => $validated['reference_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            $product = Product::findOrFail($validated['product_id']);

            if ($validated['type'] === 'addition') {
                $product->increment('stock_quantity', $validated['quantity']);
            } else {
                $product->decrement('stock_quantity', $validated['quantity']);
            }

            $movement->load(['product:id,name,sku', 'fromWarehouse:id,name,code', 'toWarehouse:id,name,code']);

            return response()->json($movement, 201);
        });
    }

    public function show(StockMovement $stockMovement): JsonResponse
    {
        $stockMovement->load(['product:id,name,sku', 'fromWarehouse:id,name,code', 'toWarehouse:id,name,code']);

        return response()->json($stockMovement);
    }

    public function transfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric|min:0.01',
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'to_warehouse_id' => 'required|exists:warehouses,id|different:from_warehouse_id',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $product = Product::findOrFail($validated['product_id']);

            if ((float) $product->stock_quantity < $validated['quantity']) {
                return response()->json([
                    'message' => "Insufficient stock. Available: {$product->stock_quantity}, requested: {$validated['quantity']}.",
                ], 422);
            }

            $movement = StockMovement::create([
                'business_id' => $request->user()->business_id,
                'product_id' => $validated['product_id'],
                'from_warehouse_id' => $validated['from_warehouse_id'],
                'to_warehouse_id' => $validated['to_warehouse_id'],
                'quantity' => $validated['quantity'],
                'type' => 'transfer',
                'notes' => $validated['notes'] ?? null,
            ]);

            $movement->load(['product:id,name,sku', 'fromWarehouse:id,name,code', 'toWarehouse:id,name,code']);

            return response()->json($movement, 201);
        });
    }
}
