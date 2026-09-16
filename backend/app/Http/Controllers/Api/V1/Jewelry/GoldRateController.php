<?php

namespace App\Http\Controllers\Api\V1\Jewelry;

use App\Http\Controllers\Controller;
use App\Models\GoldRateLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoldRateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $latestRates = GoldRateLog::selectRaw(' DISTINCT ON (carat) * ')
            ->where('business_id', $request->user()->business_id)
            ->orderBy('carat')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($latestRates);
    }
}
