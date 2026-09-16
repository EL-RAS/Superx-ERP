<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the SuperX platform-owner surface (tenant directory, provisioning,
 * subscription management). Access requires EITHER:
 *  1. An authenticated user whose role is `superx_owner`, OR
 *  2. The explicit environment secret sent as `X-Owner-Secret`, matching
 *     the SUPERX_OWNER_SECRET env/config value (server-to-server access).
 *
 * The guard performs its own sanctum resolution and answers everything
 * else with a flat 404 so the portal's existence is not disclosed.
 */
class EnsurePlatformOwner
{
    public const OWNER_ROLE = 'superx_owner';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('superx.owner_secret');

        if ($secret !== '' && hash_equals($secret, (string) $request->header('X-Owner-Secret'))) {
            return $next($request);
        }

        $user = $request->user('sanctum');

        if ($user && $user->role === self::OWNER_ROLE && $user->is_active) {
            return $next($request);
        }

        return response()->json(['message' => 'Not Found.'], 404);
    }
}
