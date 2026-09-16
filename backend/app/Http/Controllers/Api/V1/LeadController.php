<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Rules\ValidPhone;
use App\Services\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public B2B lead capture. Replaces the retired self-service registration:
 * prospects submit a demo request and the SuperX team provisions the tenant
 * manually from the platform owner portal.
 */
class LeadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'business_name' => 'required|string|max:255',
            'business_type_id' => ['required', 'integer', 'exists:business_types,id'],
            'phone' => ['required', new ValidPhone],
            'email' => 'nullable|email|max:255',
            'subdomain' => [
                'nullable',
                'string',
                'min:2',
                'max:63',
                'regex:/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/',
            ],
            'city' => 'nullable|string|max:255',
        ]);

        $lead = Lead::create([
            ...$validated,
            'phone' => PhoneNormalizer::normalize($validated['phone']),
            'subdomain' => $validated['subdomain'] ?? null,
            'status' => 'new',
        ]);

        return response()->json([
            'message' => 'Thank you! Our team will contact you shortly to schedule your demo.',
            'lead' => [
                'id' => $lead->id,
                'name' => $lead->name,
                'business_name' => $lead->business_name,
            ],
        ], 201);
    }
}
