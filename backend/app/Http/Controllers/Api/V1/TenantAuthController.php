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
}