<?php

namespace App\Http\Controllers\Api\V1\Jewelry;

use App\Http\Controllers\Controller;
use App\Models\PoliceBookEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PoliceBookEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PoliceBookEntry::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('entry_number', 'ilike', "%{$search}%");
        }

        if ($request->has('entry_type')) {
            $query->where('entry_type', $request->input('entry_type'));
        }

        if ($request->has('metal_type')) {
            $query->where('metal_type', $request->input('metal_type'));
        }

        if ($request->has('date_from')) {
            $query->where('transaction_date', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->where('transaction_date', '<=', $request->input('date_to'));
        }

        $entries = $query->with('customer:id,name')
            ->orderBy('transaction_date', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($entries);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entry_type' => 'required|in:purchase,sale,repair,consignment',
            'item_description' => 'required|string',
            'item_weight' => 'required|numeric|min:0',
            'item_carat' => 'required|integer|in:18,21,24',
            'metal_type' => 'required|in:gold,silver,platinum',
            'customer_name' => 'nullable|string',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_id_number' => 'nullable|string',
            'transaction_date' => 'required|date',
            'transaction_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['entry_number'] = 'PB-' . strtoupper(Str::random(8));

        $entry = PoliceBookEntry::create($validated);

        $entry->load('customer:id,name');

        return response()->json($entry, 201);
    }

    public function show(PoliceBookEntry $policeBookEntry): JsonResponse
    {
        $policeBookEntry->load('customer:id,name');

        return response()->json($policeBookEntry);
    }

    public function update(Request $request, PoliceBookEntry $policeBookEntry): JsonResponse
    {
        $validated = $request->validate([
            'entry_type' => 'sometimes|in:purchase,sale,repair,consignment',
            'item_description' => 'sometimes|string',
            'item_weight' => 'sometimes|numeric|min:0',
            'item_carat' => 'sometimes|integer|in:18,21,24',
            'metal_type' => 'sometimes|in:gold,silver,platinum',
            'customer_name' => 'nullable|string',
            'customer_id' => 'nullable|exists:customers,id',
            'customer_id_number' => 'nullable|string',
            'transaction_date' => 'sometimes|date',
            'transaction_amount' => 'sometimes|numeric|min:0',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $policeBookEntry->update($validated);

        return response()->json($policeBookEntry);
    }

    public function destroy(PoliceBookEntry $policeBookEntry): JsonResponse
    {
        $policeBookEntry->delete();

        return response()->json(['message' => 'Police book entry deleted.']);
    }
}
