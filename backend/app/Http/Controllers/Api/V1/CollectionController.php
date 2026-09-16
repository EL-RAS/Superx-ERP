<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    public function index(): JsonResponse
    {
        $collections = Collection::withCount('products')
            ->orderByDesc('is_active')
            ->orderBy('year', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json($collections);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateCollection($request);

        $collection = Collection::create([
            ...$validated,
            'year' => (int) $validated['year'],
            'is_active' => $validated['is_active'] ?? true,
        ]);

        $collection->loadCount('products');

        return response()->json($collection, 201);
    }

    public function show(Collection $collection): JsonResponse
    {
        $collection->loadCount('products');

        return response()->json($collection);
    }

    public function update(Request $request, Collection $collection): JsonResponse
    {
        $validated = $this->validateCollection($request, required: false);

        if (array_key_exists('year', $validated)) {
            $validated['year'] = (int) $validated['year'];
        }

        $collection->update($validated);

        $collection->loadCount('products');

        return response()->json($collection);
    }

    public function destroy(Collection $collection): JsonResponse
    {
        $collection->delete();

        return response()->json(['message' => 'Collection deleted.']);
    }

    private function validateCollection(Request $request, bool $required = true): array
    {
        $nameRule = $required ? 'required|string|max:255' : 'sometimes|string|max:255';
        $seasonRule = $required ? 'required|string|max:50' : 'sometimes|string|max:50';
        $yearRule = $required ? 'required|string|max:10' : 'sometimes|string|max:10';

        return $request->validate([
            'name' => $nameRule,
            'season' => $seasonRule,
            'year' => $yearRule,
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_active' => 'nullable|boolean',
        ]);
    }
}
