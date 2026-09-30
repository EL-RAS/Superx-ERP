<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-domain login — resolves the store by the request Host header,
 * verifies credentials against the tenant DB, and returns a central
 * Sanctum token. Public (no auth required).
 */
class TenantAuthController extends Controller
{
    public function login(Request $request, OnboardingService $onboarding): JsonResponse
    {
        $host = $request->input('host')
            ?? $request->headers->get('X-Forwarded-Host')
            ?? $request->headers->get('Host')
            ?? '';

        $validated = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $result = $onboarding->loginByHost(
            $host,
            $validated['username'],
            $validated['password'],
        );

        $status = isset($result['needs_activation']) ? 422 : 200;

        return response()->json($result, $status);
    }

    /**
     * Public, credential-free host probe.
     *
     * Lets the frontend tell an unregistered subdomain from a typo before
     * showing a login form. Returns 404 for an unknown host, which is the
     * signal the login page keys off — never render the central landing page
     * or a dead form on a host that has no store behind it.
     */
    public function resolve(Request $request, OnboardingService $onboarding): JsonResponse
    {
        $host = $request->query('host')
            ?? $request->headers->get('X-Forwarded-Host')
            ?? $request->headers->get('Host')
            ?? '';

        $store = $onboarding->resolveTenantStore($host);

        if (! $store) {
            return response()->json([
                'valid' => false,
                'message' => 'No store is registered for this address.',
            ], 404);
        }

        return response()->json(['valid' => true] + $store);
    }
}
