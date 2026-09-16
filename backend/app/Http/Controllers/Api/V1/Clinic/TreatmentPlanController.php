<?php

namespace App\Http\Controllers\Api\V1\Clinic;

use App\Http\Controllers\Controller;
use App\Models\TreatmentPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TreatmentPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TreatmentPlan::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('plan_number', 'ilike', "%{$search}%")
                    ->orWhere('title', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $plans = $query->with('customer:id,name', 'procedures')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($plans);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'appointment_id' => 'nullable|exists:appointments,id',
            'title' => 'required|string',
            'estimated_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['plan_number'] = 'TP-' . strtoupper(Str::random(8));
        $validated['status'] = 'proposed';

        $plan = TreatmentPlan::create($validated);

        $plan->load('procedures');

        return response()->json($plan, 201);
    }

    public function show(TreatmentPlan $treatmentPlan): JsonResponse
    {
        $treatmentPlan->load('procedures', 'customer:id,name');

        return response()->json($treatmentPlan);
    }

    public function update(Request $request, TreatmentPlan $treatmentPlan): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'sometimes|exists:customers,id',
            'appointment_id' => 'nullable|exists:appointments,id',
            'title' => 'sometimes|string',
            'estimated_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'status' => 'nullable|in:proposed,accepted,in_progress,completed,cancelled',
            'metadata' => 'nullable|array',
        ]);

        $treatmentPlan->update($validated);

        return response()->json($treatmentPlan);
    }

    public function updateStatus(Request $request, TreatmentPlan $treatmentPlan): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:proposed,accepted,in_progress,completed,cancelled',
        ]);

        $treatmentPlan->update(['status' => $validated['status']]);

        return response()->json($treatmentPlan);
    }

    public function destroy(TreatmentPlan $treatmentPlan): JsonResponse
    {
        $treatmentPlan->procedures()->delete();
        $treatmentPlan->delete();

        return response()->json(['message' => 'Treatment plan deleted.']);
    }
}
