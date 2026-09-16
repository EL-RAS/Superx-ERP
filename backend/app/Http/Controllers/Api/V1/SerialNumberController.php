<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SerialNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SerialNumberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SerialNumber::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('serial_number', 'ilike', "%{$search}%")
                    ->orWhere('imei', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('warranty_status')) {
            $query->where('warranty_status', $request->input('warranty_status'));
        }

        if ($request->has('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        $serialNumbers = $query->with('product:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 10));

        return response()->json($serialNumbers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'serial_number' => [
                'required',
                'string',
                Rule::unique('serial_numbers', 'serial_number')->where(fn ($query) => $query->where('business_id', $request->user()->business_id)),
            ],
            'imei' => 'nullable|string',
            'warranty_start' => 'nullable|date',
            'warranty_end' => 'nullable|date|after_or_equal:warranty_start',
            'warranty_status' => 'nullable|in:active,expired,void',
            'metadata' => 'nullable|array',
        ]);

        $validated['business_id'] = $request->user()->business_id;
        $validated['status'] = 'in_stock';
        $validated['warranty_status'] = $validated['warranty_status'] ?? 'active';

        $serialNumber = SerialNumber::create($validated);

        return response()->json($serialNumber, 201);
    }

    public function show(SerialNumber $serialNumber): JsonResponse
    {
        $serialNumber->load('product:id,name');

        return response()->json($serialNumber);
    }

    public function update(Request $request, SerialNumber $serialNumber): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'sometimes|exists:products,id',
            'serial_number' => [
                'sometimes',
                'string',
                Rule::unique('serial_numbers', 'serial_number')
                    ->where(fn ($query) => $query->where('business_id', $request->user()->business_id))
                    ->ignore($serialNumber->id),
            ],
            'imei' => 'nullable|string',
            'warranty_start' => 'nullable|date',
            'warranty_end' => 'nullable|date|after_or_equal:warranty_start',
            'warranty_status' => 'nullable|in:active,expired,void',
            'status' => 'nullable|in:in_stock,sold,returned,in_service',
            'metadata' => 'nullable|array',
        ]);

        $serialNumber->update($validated);

        return response()->json($serialNumber);
    }

    public function sell(Request $request, SerialNumber $serialNumber): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
        ]);

        $currentStatus = $serialNumber->status ?: 'in_stock';

        if ($currentStatus !== 'in_stock') {
            return response()->json([
                'message' => "Cannot sell serial number with status '{$currentStatus}'. Only 'in_stock' items can be sold.",
            ], 422);
        }

        DB::table('serial_numbers')
            ->where('id', $serialNumber->id)
            ->update([
                'status' => 'sold',
                'customer_id' => $validated['customer_id'],
                'sold_at' => now(),
                'updated_at' => now(),
            ]);

        $serialNumber->refresh();
        $serialNumber->load('product:id,name', 'customer:id,name');

        return response()->json($serialNumber);
    }

    public function destroy(SerialNumber $serialNumber): JsonResponse
    {
        $serialNumber->delete();

        return response()->json(['message' => 'Serial number deleted.']);
    }
}
