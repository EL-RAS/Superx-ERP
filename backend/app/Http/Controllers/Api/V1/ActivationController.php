<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Rules\StrongPassword;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * First-time activation wizard — validates the one-time token and creates
 * the root tenant admin account. Public (no auth required).
 */
class ActivationController extends Controller
{
    /**
     * GET /v1/activate/{token}
     *
     * Validate the activation token and return the business info
     * the wizard needs to pre-fill its form.
     */
    public function show(string $token, OnboardingService $onboarding): JsonResponse
    {
        $preview = $onboarding->activationPreview($token);

        return response()->json($preview);
    }

    /**
     * POST /v1/activate
     *
     * Consume the activation token, create the root tenant admin
     * inside the tenant DB, activate both the tenant and central
     * mirror Business, and return login information.
     */
    public function store(Request $request, OnboardingService $onboarding): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|size:64',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'username' => 'nullable|string|min:3|max:30|regex:/^[a-zA-Z0-9_]+$/',
            'password' => ['required', 'confirmed', new StrongPassword],
            'currency' => 'nullable|string|max:3',
            'tax_enabled' => 'nullable|boolean',
            'default_tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_calculation_method' => 'nullable|in:inclusive,exclusive',
        ]);

        $result = $onboarding->activate($validated['token'], $validated);

        return response()->json($result);
    }
}
