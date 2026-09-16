<?php

namespace App\Providers;

use App\Services\Messaging\MessagingService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(BusinessServiceProvider::class);

        $this->app->singleton(MessagingService::class, fn () => new MessagingService);
    }

    public function boot(): void
    {
        //
    }
}
