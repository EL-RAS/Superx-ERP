<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * SuperX Owner Portal — lead management and approval.
 * All routes live under platform.owner middleware.
 */
class PlatformLeadController extends Controller
{
    public function index(): JsonResponse
    {
        $leads = Lead::with('businessType:id,slug,name_en,name_ar')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'summary' => [
                'total' => $leads->count(),
                'new' => $leads->where('status', 'new')->count(),
                'provisioned' => $leads->where('status', 'provisioned')->count(),
            ],
            'leads' => $leads,
        ]);
    }

    public function approve(Request $request, Lead $lead, OnboardingService $onboarding): JsonResponse
    {
        $validated = $request->validate([
            'subdomain' => [
                'nullable',
                'string',
                'min:2',
                'max:63',
                'regex:/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/',
            ],
            'email' => 'sometimes|nullable|email|max:255',
            'subscription_days' => 'nullable|integer|min:1|max:3650',
            'plan' => ['nullable', Rule::in(['standard', 'premium', 'enterprise'])],
        ]);

        if ($lead->status !== 'new') {
            throw ValidationException::withMessages([
                'lead' => [$lead->isProvisioned() ? 'This lead has already been provisioned.' : 'This lead can no longer be approved.'],
            ]);
        }

        // Allow the owner to override the lead's email, subdomain, plan or
        // subscription term at approval time (useful when the anonymous lead
        // submission was incomplete).
        if (isset($validated['email']) && ! $lead->email) {
            $lead->update(['email' => $validated['email']]);
        }
        if (isset($validated['subdomain']) && ! $lead->subdomain) {
            $lead->update(['subdomain' => $validated['subdomain']]);
        }
        if (isset($validated['subscription_days']) || isset($validated['plan'])) {
            $lead->update([
                'metadata' => array_merge((array) $lead->metadata, [
                    'subscription_days' => $validated['subscription_days'] ?? (int) ($lead->metadata['subscription_days'] ?? 30),
                    'plan' => $validated['plan'] ?? (string) ($lead->metadata['plan'] ?? 'standard'),
                ]),
            ]);
        }

        // `$owner` stays null for server-to-server access via X-Owner-Secret —
        // provision() handles it (approved_by stays null).
        $result = $onboarding->provision($lead, $request->user());

        return response()->json([
            'message' => 'Tenant provisioned successfully.',
            'lead' => $result['lead'],
            'domain' => $result['domain'],
            'subdomain' => $result['subdomain'],
            'database' => $result['database'],
            'store_url' => $result['store_url'],
            'plan' => $result['business']->plan,
            'activation_url' => $result['activation_url'],
            'business_id' => $result['business']->id,
        ], 201);
    }

    public function reject(Request $request, Lead $lead): JsonResponse
    {
        if ($lead->status !== 'new') {
            throw ValidationException::withMessages([
                'lead' => ['Only new leads can be rejected.'],
            ]);
        }

        $lead->update([
            'status' => 'rejected',
            'metadata' => array_merge((array) $lead->metadata, [
                'rejected_at' => now()->toIso8601String(),
                'rejected_by' => $request->user()?->id,
            ]),
        ]);

        return response()->json($lead->fresh());
    }
}
