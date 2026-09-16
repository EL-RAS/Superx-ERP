<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WarrantyClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WarrantyClaimController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = WarrantyClaim::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('claim_number', 'ilike', "%{$search}%");
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $claims = $query->with('serialNumber:id,serial_number,product_id')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($claims);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'serial_number_id' => 'required|exists:serial_numbers,id',
            'customer_id' => 'nullable|exists:customers,id',
            'issue_description' => 'required|string',
            'technician' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['claim_number'] = 'WC-' . strtoupper(Str::random(8));
        $validated['status'] = 'open';

        $claim = WarrantyClaim::create($validated);

        $claim->load('serialNumber:id,serial_number,product_id');

        return response()->json($claim, 201);
    }

    public function show(WarrantyClaim $warrantyClaim): JsonResponse
    {
        $warrantyClaim->load('serialNumber:id,serial_number,product_id', 'customer:id,name');

        return response()->json($warrantyClaim);
    }

    public function update(Request $request, WarrantyClaim $warrantyClaim): JsonResponse
    {
        $validated = $request->validate([
            'serial_number_id' => 'sometimes|exists:serial_numbers,id',
            'customer_id' => 'nullable|exists:customers,id',
            'issue_description' => 'sometimes|string',
            'technician' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $warrantyClaim->update($validated);

        return response()->json($warrantyClaim);
    }

    public function updateStatus(Request $request, WarrantyClaim $warrantyClaim): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:open,in_repair,resolved,rejected',
            'resolution' => 'nullable|string',
        ]);

        $warrantyClaim->update([
            'status' => $validated['status'],
            'resolution' => $validated['resolution'] ?? $warrantyClaim->resolution,
            'resolved_at' => in_array($validated['status'], ['resolved', 'rejected']) ? now() : $warrantyClaim->resolved_at,
        ]);

        return response()->json($warrantyClaim);
    }

    public function destroy(WarrantyClaim $warrantyClaim): JsonResponse
    {
        $warrantyClaim->delete();

        return response()->json(['message' => 'Warranty claim deleted.']);
    }
}
