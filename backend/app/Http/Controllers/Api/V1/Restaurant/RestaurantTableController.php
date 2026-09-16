<?php

namespace App\Http\Controllers\Api\V1\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantTable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestaurantTableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RestaurantTable::query();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('section_id')) {
            $query->where('section_id', $request->input('section_id'));
        }

        $tables = $query->orderBy('number', 'asc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($tables);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'number' => 'required|string',
            'section_id' => 'nullable|string',
            'seats' => 'required|integer|min:1',
            'shape' => 'required|in:round,square,rectangle',
            'position_x' => 'nullable|integer',
            'position_y' => 'nullable|integer',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['status'] = 'available';

        $table = RestaurantTable::create($validated);

        return response()->json($table, 201);
    }

    public function show(RestaurantTable $restaurantTable): JsonResponse
    {
        return response()->json($restaurantTable);
    }

    public function update(Request $request, RestaurantTable $restaurantTable): JsonResponse
    {
        $validated = $request->validate([
            'number' => 'sometimes|string',
            'section_id' => 'nullable|string',
            'seats' => 'sometimes|integer|min:1',
            'shape' => 'sometimes|in:round,square,rectangle',
            'position_x' => 'nullable|integer',
            'position_y' => 'nullable|integer',
            'metadata' => 'nullable|array',
        ]);

        $restaurantTable->update($validated);

        return response()->json($restaurantTable);
    }

    public function updateStatus(Request $request, RestaurantTable $restaurantTable): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:available,occupied,reserved,cleaning',
        ]);

        $restaurantTable->update(['status' => $validated['status']]);

        return response()->json($restaurantTable);
    }

    public function destroy(RestaurantTable $restaurantTable): JsonResponse
    {
        $restaurantTable->delete();

        return response()->json(['message' => 'Restaurant table deleted.']);
    }
}
