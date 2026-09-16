<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ServiceTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ServiceTicket::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'ilike', "%{$search}%")
                    ->orWhere('device_name', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $tickets = $query->with('customer:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($tickets);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'device_name' => 'required|string',
            'device_serial' => 'nullable|string',
            'issue_description' => 'required|string',
            'estimated_cost' => 'nullable|numeric|min:0',
            'technician' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['ticket_number'] = 'ST-' . strtoupper(Str::random(8));
        $validated['status'] = 'open';

        $ticket = ServiceTicket::create($validated);

        $ticket->load('customer:id,name');

        return response()->json($ticket, 201);
    }

    public function show(ServiceTicket $serviceTicket): JsonResponse
    {
        $serviceTicket->load('customer:id,name');

        return response()->json($serviceTicket);
    }

    public function update(Request $request, ServiceTicket $serviceTicket): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'device_name' => 'sometimes|string',
            'device_serial' => 'nullable|string',
            'issue_description' => 'sometimes|string',
            'estimated_cost' => 'nullable|numeric|min:0',
            'actual_cost' => 'nullable|numeric|min:0',
            'technician' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $serviceTicket->update($validated);

        return response()->json($serviceTicket);
    }

    public function updateStatus(Request $request, ServiceTicket $serviceTicket): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:open,in_progress,waiting_parts,completed,delivered,cancelled',
        ]);

        $serviceTicket->update([
            'status' => $validated['status'],
            'completed_at' => in_array($validated['status'], ['completed', 'delivered']) ? now() : $serviceTicket->completed_at,
        ]);

        return response()->json($serviceTicket);
    }

    public function destroy(ServiceTicket $serviceTicket): JsonResponse
    {
        $serviceTicket->delete();

        return response()->json(['message' => 'Service ticket deleted.']);
    }
}
