<?php

namespace App\Http\Controllers\Api\V1\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Reservation::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('guest_name', 'ilike', "%{$search}%")
                    ->orWhere('guest_phone', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('date_from')) {
            $query->where('reservation_date', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->where('reservation_date', '<=', $request->input('date_to'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $reservations = $query->with('table:id,number,section_id')
            ->orderBy('reservation_date', 'desc')
            ->orderBy('reservation_time', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($reservations);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'table_id' => 'nullable|exists:restaurant_tables,id',
            'guest_name' => 'required|string',
            'guest_phone' => 'nullable|string',
            'party_size' => 'required|integer|min:1',
            'reservation_date' => 'required|date',
            'reservation_time' => 'required|string',
            'duration_minutes' => 'nullable|integer|min:15',
            'occasion' => 'nullable|string',
            'special_requests' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['status'] = 'confirmed';

        $reservation = Reservation::create($validated);

        $reservation->load('table:id,number,section_id');

        return response()->json($reservation, 201);
    }

    public function show(Reservation $reservation): JsonResponse
    {
        $reservation->load('table:id,number,section_id', 'customer:id,name');

        return response()->json($reservation);
    }

    public function update(Request $request, Reservation $reservation): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'table_id' => 'nullable|exists:restaurant_tables,id',
            'guest_name' => 'sometimes|string',
            'guest_phone' => 'nullable|string',
            'party_size' => 'sometimes|integer|min:1',
            'reservation_date' => 'sometimes|date',
            'reservation_time' => 'sometimes|string',
            'duration_minutes' => 'nullable|integer|min:15',
            'occasion' => 'nullable|string',
            'special_requests' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $reservation->update($validated);

        return response()->json($reservation);
    }

    public function updateStatus(Request $request, Reservation $reservation): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:confirmed,seated,completed,no_show,cancelled',
        ]);

        $reservation->update(['status' => $validated['status']]);

        return response()->json($reservation);
    }

    public function destroy(Reservation $reservation): JsonResponse
    {
        $reservation->delete();

        return response()->json(['message' => 'Reservation deleted.']);
    }
}
