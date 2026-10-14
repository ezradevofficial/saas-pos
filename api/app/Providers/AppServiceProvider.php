<?php

namespace App\Providers;

use App\Core\Support\EventReplays;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Stage 7B: modules register how to raise their events again (a later module's backfill).
        $this->app->singleton(EventReplays::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
