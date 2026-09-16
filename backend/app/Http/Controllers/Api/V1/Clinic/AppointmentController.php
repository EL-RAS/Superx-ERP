<?php

namespace App\Http\Controllers\Api\V1\Clinic;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AppointmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Appointment::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('appointment_number', 'ilike', "%{$search}%");
        }

        if ($request->has('date_from')) {
            $query->where('appointment_date', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->where('appointment_date', '<=', $request->input('date_to'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        $appointments = $query->with('customer:id,name,email,phone')
            ->orderBy('appointment_date', 'desc')
            ->orderBy('appointment_time', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($appointments);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'user_id' => 'nullable|exists:users,id',
            'appointment_date' => 'required|date',
            'appointment_time' => 'required|string',
            'duration_minutes' => 'nullable|integer|min:5',
            'reason' => 'nullable|string',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['appointment_number'] = 'APT-' . strtoupper(Str::random(8));
        $validated['status'] = 'scheduled';

        $appointment = Appointment::create($validated);

        $appointment->load('customer:id,name,email,phone');

        return response()->json($appointment, 201);
    }

    public function show(Appointment $appointment): JsonResponse
    {
        $appointment->load('customer:id,name,email,phone', 'user:id,name');

        return response()->json($appointment);
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'sometimes|exists:customers,id',
            'user_id' => 'nullable|exists:users,id',
            'appointment_date' => 'sometimes|date',
            'appointment_time' => 'sometimes|string',
            'duration_minutes' => 'nullable|integer|min:5',
            'reason' => 'nullable|string',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $appointment->update($validated);

        return response()->json($appointment);
    }

    public function updateStatus(Request $request, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:scheduled,confirmed,in_progress,completed,no_show,cancelled',
        ]);

        $appointment->update(['status' => $validated['status']]);

        return response()->json($appointment);
    }

    public function destroy(Appointment $appointment): JsonResponse
    {
        $appointment->delete();

        return response()->json(['message' => 'Appointment deleted.']);
    }
}
