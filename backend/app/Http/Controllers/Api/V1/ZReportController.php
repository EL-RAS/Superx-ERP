<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ZReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ZReport::with(['shift:id,shift_number', 'user:id,name'])
            ->orderByDesc('started_at');

        if ($request->has('shift_id')) {
            $query->where('shift_id', $request->integer('shift_id'));
        }

        $zReports = $query->paginate($request->integer('per_page', 10));

        return response()->json($zReports);
    }

    public function show(ZReport $zReport): JsonResponse
    {
        $zReport->load(['shift', 'user:id,name']);

        return response()->json($zReport);
    }
}
