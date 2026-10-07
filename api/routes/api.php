<?php

use App\Core\Identity\Http\Controllers\MeController;
use App\Core\Identity\Http\Controllers\PasswordResetController;
use App\Core\Identity\Http\Controllers\SessionController;
use App\Core\Identity\Http\Controllers\SignInController;
use App\Core\Identity\Http\Controllers\SignUpController;
use App\Core\Identity\Http\Controllers\TwoFactorController;
use App\Core\Identity\Http\Controllers\VerifyController;
use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\Rbac\Http\Controllers\MyPermissionsController;
use Illuminate\Support\Facades\Route;

// Prefix /api/v1 (bootstrap/app.php). AUTH-01, AUTH-03, AUTH-04, AUTH-09, AUTH-10.
Route::prefix('auth')->group(function () {
    Route::post('sign-up', SignUpController::class)->middleware('throttle:auth-ip');
    Route::post('verify', [VerifyController::class, 'verify'])->middleware('throttle:auth-ip');
    Route::post('verify/resend', [VerifyController::class, 'resend'])->middleware('throttle:auth-ip');
    Route::post('sign-in', SignInController::class)->middleware(['throttle:auth-ip', 'throttle:auth-login']);
    Route::post('two-factor/challenge', [TwoFactorController::class, 'challenge'])->middleware('throttle:auth-ip');
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware(['throttle:auth-ip', 'throttle:auth-login']);
    Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware(['throttle:auth-ip', 'throttle:auth-login']);
});

// The token sets the tenant; the language is chosen again so the tenant's
// default applies when Accept-Language does not choose one (L10N-01).
// AUTH-03: a token that may only enrol a second factor is refused everywhere
// except the routes that opt out of EnsureFullAccessToken below.
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureFullAccessToken::class])->group(function () {
    Route::withoutMiddleware(EnsureFullAccessToken::class)->group(function () {
        Route::post('auth/sign-out', [SessionController::class, 'signOut']);
        Route::get('me', [MeController::class, 'show']);
        Route::post('me/two-factor/totp', [TwoFactorController::class, 'startTotp']);
        Route::post('me/two-factor/totp/confirm', [TwoFactorController::class, 'confirmTotp']);
        Route::post('me/two-factor/sms', [TwoFactorController::class, 'startSms']);
        Route::post('me/two-factor/sms/confirm', [TwoFactorController::class, 'confirmSms']);
    });

    Route::delete('me/two-factor', [TwoFactorController::class, 'disable'])->middleware('throttle:auth-ip');
    Route::get('auth/sessions', [SessionController::class, 'index']);
    Route::delete('auth/sessions/{id}', [SessionController::class, 'destroy']);

    Route::patch('me', [MeController::class, 'update']);
    // RBAC-09: what the UI may show; the API checks every action again.
    Route::get('me/permissions', MyPermissionsController::class);
});
