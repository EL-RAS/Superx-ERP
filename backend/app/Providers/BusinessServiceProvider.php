<?php

namespace App\Providers;

use App\Services\BusinessContext;
use Illuminate\Support\ServiceProvider;

class BusinessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BusinessContext::class, function () {
            return new BusinessContext;
        });
    }

    public function boot(): void
    {
        //
    }
}
