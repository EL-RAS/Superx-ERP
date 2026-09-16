<?php

namespace App\Http\Controllers\Api\V1\Clinic;

use App\Http\Controllers\Controller;
use App\Models\InsuranceClaim;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InsuranceClaimController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = InsuranceClaim::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('claim_number', 'ilike', "%{$search}%");
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('insurance_provider')) {
            $query->where('insurance_provider', 'ilike', "%{$request->input('insurance_provider')}%");
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        $claims = $query->with('customer:id,name', 'invoice:id,invoice_number')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($claims);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'insurance_provider' => 'required|string',
            'policy_number' => 'nullable|string',
            'claim_amount' => 'required|numeric|min:0',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['claim_number'] = 'IC-' . strtoupper(Str::random(8));
        $validated['status'] = 'submitted';

        $claim = InsuranceClaim::create($validated);

        $claim->load('customer:id,name', 'invoice:id,invoice_number');

        return response()->json($claim, 201);
    }

    public function show(InsuranceClaim $insuranceClaim): JsonResponse
    {
        $insuranceClaim->load('customer:id,name', 'invoice:id,invoice_number');

        return response()->json($insuranceClaim);
    }

    public function update(Request $request, InsuranceClaim $insuranceClaim): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'sometimes|exists:customers,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'insurance_provider' => 'sometimes|string',
            'policy_number' => 'nullable|string',
            'claim_amount' => 'sometimes|numeric|min:0',
            'metadata' => 'nullable|array',
        ]);

        $insuranceClaim->update($validated);

        return response()->json($insuranceClaim);
    }

    public function updateStatus(Request $request, InsuranceClaim $insuranceClaim): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:submitted,processing,approved,partially_approved,rejected,paid',
            'approved_amount' => 'nullable|numeric|min:0',
            'rejection_reason' => 'nullable|string',
        ]);

        $updateData = ['status' => $validated['status']];

        if (isset($validated['approved_amount'])) {
            $updateData['approved_amount'] = $validated['approved_amount'];
        }

        if (isset($validated['rejection_reason'])) {
            $updateData['rejection_reason'] = $validated['rejection_reason'];
        }

        if (in_array($validated['status'], ['approved', 'paid'])) {
            $updateData['processed_at'] = now();
        }

        $insuranceClaim->update($updateData);

        return response()->json($insuranceClaim);
    }

    public function destroy(InsuranceClaim $insuranceClaim): JsonResponse
    {
        $insuranceClaim->delete();

        return response()->json(['message' => 'Insurance claim deleted.']);
    }
}
