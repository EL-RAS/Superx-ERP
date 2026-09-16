<?php

namespace App\Http\Controllers\Api\V1\Jewelry;

use App\Http\Controllers\Controller;
use App\Models\RepairTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RepairTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RepairTicket::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'ilike', "%{$search}%")
                    ->orWhere('item_description', 'ilike', "%{$search}%");
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
            'item_description' => 'required|string',
            'item_weight' => 'nullable|numeric|min:0',
            'item_carat' => 'nullable|integer|in:18,21,24',
            'issue_description' => 'required|string',
            'estimated_cost' => 'nullable|numeric|min:0',
            'technician' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['ticket_number'] = 'RT-' . strtoupper(Str::random(8));
        $validated['status'] = 'received';

        $ticket = RepairTicket::create($validated);

        $ticket->load('customer:id,name');

        return response()->json($ticket, 201);
    }

    public function show(RepairTicket $repairTicket): JsonResponse
    {
        $repairTicket->load('customer:id,name');

        return response()->json($repairTicket);
    }

    public function update(Request $request, RepairTicket $repairTicket): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
            'item_description' => 'sometimes|string',
            'item_weight' => 'nullable|numeric|min:0',
            'item_carat' => 'nullable|integer|in:18,21,24',
            'issue_description' => 'sometimes|string',
            'estimated_cost' => 'nullable|numeric|min:0',
            'actual_cost' => 'nullable|numeric|min:0',
            'technician' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $repairTicket->update($validated);

        return response()->json($repairTicket);
    }

    public function updateStatus(Request $request, RepairTicket $repairTicket): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:received,diagnosed,in_repair,completed,delivered,cancelled',
        ]);

        $repairTicket->update([
            'status' => $validated['status'],
            'completed_at' => in_array($validated['status'], ['completed', 'delivered']) ? now() : $repairTicket->completed_at,
        ]);

        return response()->json($repairTicket);
    }

    public function destroy(RepairTicket $repairTicket): JsonResponse
    {
        $repairTicket->delete();

        return response()->json(['message' => 'Repair ticket deleted.']);
    }
}
