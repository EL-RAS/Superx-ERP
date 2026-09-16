<?php

namespace App\Http\Controllers\Api\V1\Clinic;

use App\Http\Controllers\Controller;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrescriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Prescription::query();

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        $prescriptions = $query->with('items', 'customer:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($prescriptions);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'medical_record_id' => 'nullable|exists:medical_records,id',
            'appointment_id' => 'nullable|exists:appointments,id',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
            'items' => 'required|array',
            'items.*.medication_name' => 'required|string',
            'items.*.dosage' => 'required|string',
            'items.*.frequency' => 'required|string',
            'items.*.duration' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.instructions' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $prescription = Prescription::create([
                'business_id' => $request->user()->business_id,
                'customer_id' => $validated['customer_id'],
                'medical_record_id' => $validated['medical_record_id'] ?? null,
                'appointment_id' => $validated['appointment_id'] ?? null,
                'prescription_number' => 'RX-' . strtoupper(Str::random(8)),
                'notes' => $validated['notes'] ?? null,
                'metadata' => $validated['metadata'] ?? null,
                'user_id' => $request->user()->id,
            ]);

            foreach ($validated['items'] as $item) {
                PrescriptionItem::create([
                    'business_id' => $request->user()->business_id,
                    'prescription_id' => $prescription->id,
                    'medication_name' => $item['medication_name'],
                    'dosage' => $item['dosage'],
                    'frequency' => $item['frequency'],
                    'duration' => $item['duration'],
                    'quantity' => $item['quantity'],
                    'instructions' => $item['instructions'] ?? null,
                ]);
            }

            $prescription->load('items', 'customer:id,name');

            return response()->json($prescription, 201);
        });
    }

    public function show(Prescription $prescription): JsonResponse
    {
        $prescription->load('items', 'customer:id,name', 'user:id,name');

        return response()->json($prescription);
    }

    public function update(Request $request, Prescription $prescription): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'sometimes|exists:customers,id',
            'medical_record_id' => 'nullable|exists:medical_records,id',
            'appointment_id' => 'nullable|exists:appointments,id',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
            'items' => 'nullable|array',
            'items.*.medication_name' => 'required_with:items|string',
            'items.*.dosage' => 'required_with:items|string',
            'items.*.frequency' => 'required_with:items|string',
            'items.*.duration' => 'required_with:items|string',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.instructions' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($validated, $prescription) {
            $prescriptionFields = collect($validated)->except('items')->filter()->toArray();
            if (!empty($prescriptionFields)) {
                $prescription->update($prescriptionFields);
            }

            if (isset($validated['items'])) {
                $prescription->items()->delete();

                foreach ($validated['items'] as $item) {
                    PrescriptionItem::create([
                        'business_id' => $prescription->business_id,
                        'prescription_id' => $prescription->id,
                        'medication_name' => $item['medication_name'],
                        'dosage' => $item['dosage'],
                        'frequency' => $item['frequency'],
                        'duration' => $item['duration'],
                        'quantity' => $item['quantity'],
                        'instructions' => $item['instructions'] ?? null,
                    ]);
                }
            }

            $prescription->load('items', 'customer:id,name');

            return response()->json($prescription);
        });
    }

    public function destroy(Prescription $prescription): JsonResponse
    {
        $prescription->items()->delete();
        $prescription->delete();

        return response()->json(['message' => 'Prescription deleted.']);
    }
}
