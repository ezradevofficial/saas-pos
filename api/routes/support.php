<?php

// Loaded by routes/api.php (prefix /api/v1). RBAC-13, ADM-03: support access with consent.

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\Platform\Http\Middleware\EnsurePlatformAccess;
use App\Core\Platform\SupportAccess\Http\Controllers\PlatformSupportController;
use App\Core\Platform\SupportAccess\Http\Controllers\SupportAccessController;
use App\Core\Platform\SupportAccess\Http\Controllers\SupportSessionController;
use App\Core\Platform\SupportAccess\Models\SupportAccessGrant;
use Illuminate\Support\Facades\Route;

Route::pattern('support_access_grant', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::model('support_access_grant', SupportAccessGrant::class);

// RBAC-13c: the console's one-time entry code for the session's token. The
// code (tenant id and a 48-character secret, stored as sha256, 60 seconds,
// used once) is the credential; rate-limited per IP.
Route::post('auth/support-session', [SupportSessionController::class, 'exchange'])->middleware('throttle:auth-ip');

// The tenant decides (Settings → Security → Support access); the principal ends its own session.
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class])->group(function () {
    Route::get('support-access', [SupportAccessController::class, 'index']);
    Route::get('support-access/active', [SupportAccessController::class, 'active']);
    Route::post('support-access/{support_access_grant}/approve', [SupportAccessController::class, 'approve']);
    Route::post('support-access/{support_access_grant}/deny', [SupportAccessController::class, 'deny']);
    Route::post('support-access/{support_access_grant}/revoke', [SupportAccessController::class, 'revoke']);
    Route::get('support-access/{support_access_grant}/activity', [SupportAccessController::class, 'activity']);

    Route::post('support-session/end', [SupportSessionController::class, 'end']);
});

// Staff (platform tokens only): ask, cancel, open and end sessions.
Route::middleware(['auth:platform', EnsurePlatformAccess::class])->prefix('platform')->group(function () {
    Route::get('support/requests', [PlatformSupportController::class, 'index']);
    Route::post('tenants/{tenant}/support-requests', [PlatformSupportController::class, 'store'])->whereUuid('tenant');
    Route::post('support/requests/{support_request}/cancel', [PlatformSupportController::class, 'cancel'])->whereUuid('support_request');
    Route::post('support/requests/{support_request}/session', [PlatformSupportController::class, 'session'])->whereUuid('support_request');
    Route::post('support/requests/{support_request}/end', [PlatformSupportController::class, 'end'])->whereUuid('support_request');
});
