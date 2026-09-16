<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * Guard a route with a module name ("permission:accounting") or a full
     * permission key ("permission:accounting.view"). A bare module resolves
     * the action from the HTTP method (GET/HEAD->view, POST->create,
     * PUT/PATCH->edit, DELETE->delete). Multiple params are OR-ed.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if (strtoupper((string) $request->method()) === 'OPTIONS') {
            return $next($request);
        }

        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $required = [];

        foreach ($permissions as $permission) {
            $required[] = str_contains($permission, '.')
                ? $permission
                : $permission.'.'.$this->actionForMethod($request->method());
        }

        if ($user->hasAnyPermission($required)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'You do not have permission to access this resource.',
        ], 403);
    }

    private function actionForMethod(string $method): string
    {
        return match (strtoupper($method)) {
            'GET', 'HEAD' => 'view',
            'POST' => 'create',
            'PUT', 'PATCH' => 'edit',
            'DELETE' => 'delete',
            default => 'view',
        };
    }
}
