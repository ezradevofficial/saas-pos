<?php

namespace App\Core\Manual;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** The printed user manual: topics in docs/manual, built into PDFs per role template. */
class ManualServiceProvider extends ServiceProvider
{
    public const PDF_LIMITER = 'manual-pdf';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../config/manual.php', 'manual');

        $this->app->singleton(TopicLibrary::class, fn () => new TopicLibrary(rtrim((string) config('manual.path'), '/')));
        $this->app->singleton(ManualBuilder::class, fn ($app) => new ManualBuilder($app->make(TopicLibrary::class), (string) config('manual.tokens')));
    }

    public function boot(): void
    {
        RateLimiter::for(self::PDF_LIMITER, fn (Request $request) => Limit::perMinute(6)->by('user|'.$request->user()?->getAuthIdentifier()));

        if ($this->app->runningInConsole()) {
            $this->commands([ManualBuildCommand::class]);
        }
    }
}
