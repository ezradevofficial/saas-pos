<?php

use App\Core\Identity\Http\Controllers\MeController;
use App\Core\Identity\Http\Controllers\SessionController;
use App\Core\Identity\Http\Controllers\SignInController;
use App\Core\Identity\Http\Controllers\SignUpController;
use App\Core\Identity\Http\Controllers\VerifyController;
use App\Core\Localisation\Http\ApplyTenantLocale;
use Illuminate\Support\Facades\Route;

// Prefix /api/v1 (bootstrap/app.php). AUTH-01, AUTH-09, AUTH-10.
Route::prefix('auth')->group(function () {
    Route::post('sign-up', SignUpController::class)->middleware('throttle:auth-ip');
    Route::post('verify', [VerifyController::class, 'verify'])->middleware('throttle:auth-ip');
    Route::post('verify/resend', [VerifyController::class, 'resend'])->middleware('throttle:auth-ip');
    Route::post('sign-in', SignInController::class)->middleware(['throttle:auth-ip', 'throttle:auth-login']);
});

// The token sets the tenant; the language is chosen again so the tenant's
// default applies when Accept-Language does not choose one (L10N-01).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class])->group(function () {
    Route::post('auth/sign-out', [SessionController::class, 'signOut']);
    Route::get('auth/sessions', [SessionController::class, 'index']);
    Route::delete('auth/sessions/{id}', [SessionController::class, 'destroy']);

    Route::get('me', [MeController::class, 'show']);
    Route::patch('me', [MeController::class, 'update']);
});
