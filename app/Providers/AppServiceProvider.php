<?php

namespace App\Providers;

use App\Services\Core\SettingsService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request/process: it holds the settings cache.
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        //
    }
}
