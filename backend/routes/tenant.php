<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Routes in this file are identified via the X-Business-ID header
| (InitializeTenancyByRequestData). No tenant routes are registered
| yet — this file exists as the landing spot for Phase 2 migration
| of the ~150 api.php tenant routes.
|
*/

Route::middleware([
    'tenant',
])->group(function () {
    // No tenant routes yet — Phase 1 is install + landlord skeleton.
});
