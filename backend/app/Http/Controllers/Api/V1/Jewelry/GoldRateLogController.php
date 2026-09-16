<?php

namespace App\Http\Controllers\Api\V1\Jewelry;

use App\Http\Controllers\Controller;
use App\Models\GoldRateLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoldRateLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = GoldRateLog::query();

        if ($request->has('carat')) {
            $query->where('carat', $request->input('carat'));
        }

        if ($request->has('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }

        $rates = $query->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($rates);
    }

    public function latest(Request $request): JsonResponse
    {
        $latestRates = GoldRateLog::selectRaw(' DISTINCT ON (carat) * ')
            ->where('business_id', $request->user()->business_id)
            ->orderBy('carat')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($latestRates);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'carat' => 'required|integer|in:18,21,24',
            'rate_per_gram' => 'required|numeric|min:0',
            'source' => 'nullable|in:manual,api',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['source'] = $validated['source'] ?? 'manual';

        $rate = GoldRateLog::create($validated);

        return response()->json($rate, 201);
    }

    public function show(GoldRateLog $goldRateLog): JsonResponse
    {
        return response()->json($goldRateLog);
    }

    public function update(Request $request, GoldRateLog $goldRateLog): JsonResponse
    {
        $validated = $request->validate([
            'carat' => 'sometimes|integer|in:18,21,24',
            'rate_per_gram' => 'sometimes|numeric|min:0',
            'source' => 'nullable|in:manual,api',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $goldRateLog->update($validated);

        return response()->json($goldRateLog);
    }

    public function destroy(GoldRateLog $goldRateLog): JsonResponse
    {
        $goldRateLog->delete();

        return response()->json(['message' => 'Gold rate log deleted.']);
    }
}
