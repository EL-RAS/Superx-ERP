<?php

namespace App\Http\Controllers\Api\V1\Clinic;

use App\Http\Controllers\Controller;
use App\Models\MedicalRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MedicalRecordController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MedicalRecord::query();

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        $records = $query->with('customer:id,name', 'user:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($records);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'appointment_id' => 'nullable|exists:appointments,id',
            'subjective' => 'nullable|string',
            'objective' => 'nullable|string',
            'assessment' => 'nullable|string',
            'plan' => 'nullable|string',
            'vitals' => 'nullable|array',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['user_id'] = $request->user()->id;

        $record = MedicalRecord::create($validated);

        $record->load('customer:id,name', 'user:id,name');

        return response()->json($record, 201);
    }

    public function show(MedicalRecord $medicalRecord): JsonResponse
    {
        $medicalRecord->load(
            'customer:id,name',
            'user:id,name',
            'prescriptions.items',
            'labOrders'
        );

        return response()->json($medicalRecord);
    }

    public function update(Request $request, MedicalRecord $medicalRecord): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'sometimes|exists:customers,id',
            'appointment_id' => 'nullable|exists:appointments,id',
            'subjective' => 'nullable|string',
            'objective' => 'nullable|string',
            'assessment' => 'nullable|string',
            'plan' => 'nullable|string',
            'vitals' => 'nullable|array',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $medicalRecord->update($validated);

        return response()->json($medicalRecord);
    }

    public function destroy(MedicalRecord $medicalRecord): JsonResponse
    {
        $medicalRecord->delete();

        return response()->json(['message' => 'Medical record deleted.']);
    }
}
