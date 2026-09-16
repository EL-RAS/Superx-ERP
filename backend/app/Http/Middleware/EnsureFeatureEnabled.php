<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level feature-flag gate.
 *
 * Usage: ->middleware('feature:crm_enabled') — aborts with 404 when the
 * underlying config('features.<name>') flag is falsy. Middleware runs at
 * request time, so tests can flip config/features in setUp after the route
 * table has already been registered.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! (bool) config("features.{$feature}")) {
            abort(404);
        }

        return $next($request);
    }
}