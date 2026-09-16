<?php

namespace App\Http\Controllers\Api\V1\Clinic;

use App\Http\Controllers\Controller;
use App\Models\DentalChart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DentalChartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DentalChart::query();

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        $charts = $query->with('customer:id,name')
            ->orderBy('tooth_number', 'asc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($charts);
    }

    public function patientChart($customerId): JsonResponse
    {
        $teeth = DentalChart::where('customer_id', $customerId)
            ->orderBy('tooth_number', 'asc')
            ->get();

        $allTeeth = collect(range(1, 32))->map(function ($toothNumber) use ($teeth) {
            $record = $teeth->firstWhere('tooth_number', $toothNumber);

            return [
                'tooth_number' => $toothNumber,
                'condition' => $record?->condition ?? 'healthy',
                'surface' => $record?->surface ?? null,
                'notes' => $record?->notes ?? null,
                'metadata' => $record?->metadata ?? null,
            ];
        });

        return response()->json([
            'customer_id' => $customerId,
            'teeth' => $allTeeth,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'tooth_number' => 'required|integer|between:1,32',
            'condition' => 'required|in:healthy,decayed,filled,extracted,crown,bridge,implant,missing',
            'surface' => 'nullable|string',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;

        $existing = DentalChart::where('business_id', $request->user()->business_id)
            ->where('customer_id', $validated['customer_id'])
            ->where('tooth_number', $validated['tooth_number'])
            ->first();

        if ($existing) {
            $existing->update($validated);

            return response()->json($existing);
        }

        $chart = DentalChart::create($validated);

        return response()->json($chart, 201);
    }

    public function show(DentalChart $dentalChart): JsonResponse
    {
        $dentalChart->load('customer:id,name');

        return response()->json($dentalChart);
    }

    public function update(Request $request, DentalChart $dentalChart): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'sometimes|exists:customers,id',
            'tooth_number' => 'sometimes|integer|between:1,32',
            'condition' => 'sometimes|in:healthy,decayed,filled,extracted,crown,bridge,implant,missing',
            'surface' => 'nullable|string',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $dentalChart->update($validated);

        return response()->json($dentalChart);
    }

    public function bulkStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'teeth' => 'required|array',
            'teeth.*.tooth_number' => 'required|integer|between:1,32',
            'teeth.*.condition' => 'required|in:healthy,decayed,filled,extracted,crown,bridge,implant,missing',
            'teeth.*.surface' => 'nullable|string',
            'teeth.*.notes' => 'nullable|string',
        ]);

        $businessId = $request->user()->business_id;

        $results = collect($validated['teeth'])->map(function ($tooth) use ($businessId, $validated) {
            $data = [
                'business_id' => $businessId,
                'customer_id' => $validated['customer_id'],
                'tooth_number' => $tooth['tooth_number'],
                'condition' => $tooth['condition'],
                'surface' => $tooth['surface'] ?? null,
                'notes' => $tooth['notes'] ?? null,
            ];

            $existing = DentalChart::where('business_id', $businessId)
                ->where('customer_id', $validated['customer_id'])
                ->where('tooth_number', $tooth['tooth_number'])
                ->first();

            if ($existing) {
                $existing->update($data);

                return $existing;
            }

            return DentalChart::create($data);
        });

        return response()->json([
            'message' => 'Dental chart updated successfully.',
            'teeth' => $results,
        ], 201);
    }

    public function destroy(DentalChart $dentalChart): JsonResponse
    {
        $dentalChart->delete();

        return response()->json(['message' => 'Dental chart entry deleted.']);
    }
}
