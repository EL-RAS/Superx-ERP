<?php

use App\Exceptions\InsufficientStockException;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePlatformOwner;
use App\Http\Middleware\IdentifyBusiness;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'business' => IdentifyBusiness::class,
            'permission' => EnsurePermission::class,
            'platform.owner' => EnsurePlatformOwner::class,
            'feature' => EnsureFeatureEnabled::class,
            'tenant' => \Stancl\Tenancy\Middleware\InitializeTenancyByRequestData::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (InsufficientStockException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response($e->getMessage(), 422);
        });
    })->create();
