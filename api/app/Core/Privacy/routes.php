<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\Privacy\Http\Controllers\ConsentController;
use App\Core\Privacy\Http\Controllers\PrivacyRequestController;
use Illuminate\Support\Facades\Route;

// NFR-08: data subject requests and marketing consent (Settings → Privacy,
// docs/privacy.md). Signed-in users of a tenant only; each Form Request
// checks its permission at tenant scope.
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class])->group(function () {
    Route::get('privacy/requests', [PrivacyRequestController::class, 'index']);
    Route::post('privacy/requests', [PrivacyRequestController::class, 'store']);
    Route::get('privacy/requests/{privacy_request}', [PrivacyRequestController::class, 'show']);
    Route::post('privacy/requests/{privacy_request}/approve', [PrivacyRequestController::class, 'approve']);
    Route::post('privacy/requests/{privacy_request}/reject', [PrivacyRequestController::class, 'reject']);
    Route::post('privacy/requests/{privacy_request}/execute', [PrivacyRequestController::class, 'execute']);
    Route::get('privacy/requests/{privacy_request}/report', [PrivacyRequestController::class, 'report']);
    Route::get('privacy/subjects', [PrivacyRequestController::class, 'subjects']);

    Route::get('privacy/consents', [ConsentController::class, 'index']);
    Route::post('privacy/consents', [ConsentController::class, 'store']);
});
