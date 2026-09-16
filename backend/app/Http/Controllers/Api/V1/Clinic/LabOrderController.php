<?php

namespace App\Http\Controllers\Api\V1\Clinic;

use App\Http\Controllers\Controller;
use App\Models\LabOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LabOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LabOrder::query();

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('test_type')) {
            $query->where('test_type', $request->input('test_type'));
        }

        $orders = $query->with('customer:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($orders);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'medical_record_id' => 'nullable|exists:medical_records,id',
            'test_name' => 'required|string',
            'test_type' => 'required|in:blood,urine,imaging,other',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['order_number'] = 'LAB-' . strtoupper(Str::random(8));
        $validated['status'] = 'ordered';

        $order = LabOrder::create($validated);

        $order->load('customer:id,name');

        return response()->json($order, 201);
    }

    public function show(LabOrder $labOrder): JsonResponse
    {
        $labOrder->load('customer:id,name', 'medicalRecord:id');

        return response()->json($labOrder);
    }

    public function update(Request $request, LabOrder $labOrder): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'sometimes|exists:customers,id',
            'medical_record_id' => 'nullable|exists:medical_records,id',
            'test_name' => 'sometimes|string',
            'test_type' => 'sometimes|in:blood,urine,imaging,other',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $labOrder->update($validated);

        return response()->json($labOrder);
    }

    public function updateResults(Request $request, LabOrder $labOrder): JsonResponse
    {
        $validated = $request->validate([
            'results' => 'required|array',
            'results_notes' => 'nullable|string',
        ]);

        $labOrder->update([
            'results' => $validated['results'],
            'results_notes' => $validated['results_notes'] ?? null,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return response()->json($labOrder);
    }

    public function destroy(LabOrder $labOrder): JsonResponse
    {
        $labOrder->delete();

        return response()->json(['message' => 'Lab order deleted.']);
    }
}
