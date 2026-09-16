<?php

namespace App\Http\Controllers\Api\V1\Clinic;

use App\Http\Controllers\Controller;
use App\Models\TreatmentProcedure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TreatmentProcedureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TreatmentProcedure::query();

        if ($request->has('treatment_plan_id')) {
            $query->where('treatment_plan_id', $request->input('treatment_plan_id'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $procedures = $query->with('treatmentPlan:id,plan_number,title')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($procedures);
    }

    public function store(Request $request, $treatmentPlanId): JsonResponse
    {
        $validated = $request->validate([
            'tooth_number' => 'nullable|integer|between:1,32',
            'procedure_code' => 'required|string',
            'procedure_name' => 'required|string',
            'description' => 'nullable|string',
            'cost' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['treatment_plan_id'] = $treatmentPlanId;
        $validated['status'] = 'planned';

        $procedure = TreatmentProcedure::create($validated);

        $procedure->load('treatmentPlan:id,plan_number,title');

        return response()->json($procedure, 201);
    }

    public function show(TreatmentProcedure $treatmentProcedure): JsonResponse
    {
        $treatmentProcedure->load('treatmentPlan:id,plan_number,title');

        return response()->json($treatmentProcedure);
    }

    public function update(Request $request, TreatmentProcedure $treatmentProcedure): JsonResponse
    {
        $validated = $request->validate([
            'tooth_number' => 'nullable|integer|between:1,32',
            'procedure_code' => 'sometimes|string',
            'procedure_name' => 'sometimes|string',
            'description' => 'nullable|string',
            'cost' => 'sometimes|numeric|min:0',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $treatmentProcedure->update($validated);

        return response()->json($treatmentProcedure);
    }

    public function updateStatus(Request $request, TreatmentProcedure $treatmentProcedure): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:planned,in_progress,completed,cancelled',
        ]);

        $treatmentProcedure->update(['status' => $validated['status']]);

        return response()->json($treatmentProcedure);
    }

    public function destroy(TreatmentProcedure $treatmentProcedure): JsonResponse
    {
        $treatmentProcedure->delete();

        return response()->json(['message' => 'Treatment procedure deleted.']);
    }
}
