<?php

use App\Core\Http\ApiErrorRenderer;
use App\Core\Localisation\Http\SetLocale;
use App\Core\Tenancy\Http\RequireTenant;
use App\Core\Tenancy\Http\ResetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TEN-01: every request starts without a tenant; routes that need one
        // use `tenant` after auth.
        $middleware->prepend(ResetTenantContext::class);
        // L10N-01: first in the group, so even an authentication error is
        // translated; ApplyTenantLocale repeats the choice after auth.
        $middleware->api(prepend: [SetLocale::class]);
        $middleware->alias(['tenant' => RequireTenant::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // API errors: {message, code, errors?} with a translated message.
        $exceptions->render(fn (Throwable $e, Request $request) => ApiErrorRenderer::render($e, $request));
    })->create();
