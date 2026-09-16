<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Services\BusinessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyBusiness
{
    public function __construct(
        protected BusinessContext $businessContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $businessId = $request->header('X-Business-ID');

        if (! $businessId) {
            if ($request->user()) {
                $businessId = $request->user()->business_id;
            }
        }

        if (! $businessId) {
            return response()->json([
                'message' => 'Business identifier is missing. Provide X-Business-ID header.',
            ], 400);
        }

        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $businessId)) {
            return response()->json([
                'message' => 'Invalid business ID format. Must be a valid UUID.',
            ], 400);
        }

        $business = Business::query()
            ->where('id', $businessId)
            ->first();

        if (! $business) {
            return response()->json([
                'message' => 'Business not found or is suspended.',
            ], 404);
        }

        // ── Subscription gate (hard lockout) ─────────────────────────
        // Suspended tenants and tenants past their expiration date are cut
        // off from every business-scoped endpoint until the SuperX owner
        // restores the subscription. The distinct codes let the frontend
        // route to the dedicated subscription-expired screen.
        if ($business->isSuspended()) {
            return response()->json([
                'message' => 'Your business account is suspended. Please contact SuperX support to restore access.',
                'code' => 'subscription_suspended',
                'subscription' => $business->subscriptionPayload(),
            ], 403);
        }

        if ($business->isExpired()) {
            return response()->json([
                'message' => 'Your SuperX subscription has expired. Please contact SuperX support to restore access.',
                'code' => 'subscription_expired',
                'subscription' => $business->subscriptionPayload(),
            ], 403);
        }

        $user = $request->user();
        if ($user && $user->business_id !== $business->id) {
            return response()->json([
                'message' => 'You do not have access to this business.',
            ], 403);
        }

        $this->businessContext->setBusiness($business);

        config(['superx.business_id' => $business->id]);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->businessContext->clear();
    }
}
