<?php

use App\Core\Identity\Http\Controllers\AcceptInvitationController;
use App\Core\Identity\Http\Controllers\InvitationController;
use App\Core\Identity\Http\Controllers\MeController;
use App\Core\Identity\Http\Controllers\PasswordResetController;
use App\Core\Identity\Http\Controllers\SessionController;
use App\Core\Identity\Http\Controllers\SignInController;
use App\Core\Identity\Http\Controllers\SignUpController;
use App\Core\Identity\Http\Controllers\TwoFactorController;
use App\Core\Identity\Http\Controllers\UserController;
use App\Core\Identity\Http\Controllers\VerifyController;
use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\Rbac\Http\Controllers\AccessReviewController;
use App\Core\Rbac\Http\Controllers\AssignmentController;
use App\Core\Rbac\Http\Controllers\MyPermissionsController;
use App\Core\Rbac\Http\Controllers\PermissionCatalogueController;
use App\Core\Rbac\Http\Controllers\RoleController;
use App\Core\Tenancy\Http\Controllers\BranchController;
use App\Core\Tenancy\Http\Controllers\CompanyController;
use App\Core\Tenancy\Http\Controllers\DeviceController;
use App\Core\Tenancy\Http\Controllers\DevicePairingController;
use App\Core\Tenancy\Http\Controllers\LocationController;
use App\Core\Tenancy\Http\EnsureDeviceToken;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Route keys are UUIDs: anything else is not found, never a database error.
foreach (['company', 'branch', 'location', 'device', 'user', 'role', 'invitation', 'assignment'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

// Prefix /api/v1 (bootstrap/app.php). AUTH-01, AUTH-03, AUTH-04, AUTH-09, AUTH-10.
Route::prefix('auth')->group(function () {
    Route::post('sign-up', SignUpController::class)->middleware('throttle:auth-ip');
    Route::post('verify', [VerifyController::class, 'verify'])->middleware('throttle:auth-ip');
    Route::post('verify/resend', [VerifyController::class, 'resend'])->middleware('throttle:auth-ip');
    Route::post('sign-in', SignInController::class)->middleware(['throttle:auth-ip', 'throttle:auth-login']);
    Route::post('two-factor/challenge', [TwoFactorController::class, 'challenge'])->middleware('throttle:auth-ip');
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware(['throttle:auth-ip', 'throttle:auth-login']);
    Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware(['throttle:auth-ip', 'throttle:auth-login']);

    // AUTH-05: an invitee sees whose invitation it is, then accepts it.
    Route::get('invitations/{token}', [AcceptInvitationController::class, 'show'])->middleware('throttle:auth-ip')->where('token', '[A-Za-z0-9]{40}');
    Route::post('invitations/{token}/accept', [AcceptInvitationController::class, 'accept'])->middleware('throttle:auth-ip')->where('token', '[A-Za-z0-9]{40}');
});

// TEN-05: a POS device exchanges its one-time pairing code for a token.
Route::post('devices/pair', [DevicePairingController::class, 'pair'])->middleware('throttle:device-pair');

// TEN-05: routes for a paired device's token only (ability `device`).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureDeviceToken::class])->group(function () {
    Route::get('devices/me', [DevicePairingController::class, 'me']);
});

// The token sets the tenant; the language is chosen again so the tenant's
// default applies when Accept-Language does not choose one (L10N-01).
// AUTH-03: a token that may only enrol a second factor is refused everywhere
// except the routes that opt out of EnsureFullAccessToken below.
// EnsureUserToken: a device token never reaches back-office routes (TEN-05).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class])->group(function () {
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

    // TEN-02..TEN-06: the organisation. Lists show only what the user's
    // scopes reach (RBAC-04); records are archived, never deleted.
    Route::get('branches', [BranchController::class, 'all']);
    Route::get('locations', [LocationController::class, 'all']);
    Route::apiResource('companies', CompanyController::class)->except('destroy');
    Route::apiResource('companies.branches', BranchController::class)->shallow()->except('destroy');
    Route::apiResource('branches.locations', LocationController::class)->shallow()->except('destroy');
    Route::apiResource('locations.devices', DeviceController::class)->shallow()->except('destroy');

    foreach (['companies' => CompanyController::class, 'branches' => BranchController::class, 'locations' => LocationController::class] as $resource => $controller) {
        $parameter = Str::singular($resource);
        Route::post("{$resource}/{{$parameter}}/archive", [$controller, 'archive']);
        Route::post("{$resource}/{{$parameter}}/restore", [$controller, 'restore']);
    }

    Route::post('devices/{device}/pairing-code', [DeviceController::class, 'pairingCode']);
    Route::post('devices/{device}/suspend', [DeviceController::class, 'suspend']);
    Route::post('devices/{device}/resume', [DeviceController::class, 'resume']);
    Route::post('devices/{device}/unpair', [DeviceController::class, 'unpair']);

    // AUTH-05, AUTH-13: users and invitations, in the actor's scope (RBAC-04).
    Route::get('users', [UserController::class, 'index']);
    Route::get('users/{user}', [UserController::class, 'show']);
    Route::patch('users/{user}', [UserController::class, 'update']);
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate']);
    Route::post('users/{user}/reactivate', [UserController::class, 'reactivate']);
    Route::post('users/{user}/sign-out-everywhere', [UserController::class, 'signOutEverywhere']);
    Route::get('invitations', [InvitationController::class, 'index']);
    Route::post('invitations', [InvitationController::class, 'store']);
    Route::post('invitations/{invitation}/revoke', [InvitationController::class, 'revoke']);

    // RBAC-02, RBAC-04, RBAC-10..RBAC-12: roles, assignments, access review.
    Route::get('roles', [RoleController::class, 'index']);
    Route::post('roles', [RoleController::class, 'store']);
    Route::get('roles/{role}', [RoleController::class, 'show']);
    Route::patch('roles/{role}', [RoleController::class, 'update']);
    Route::post('roles/{role}/copy', [RoleController::class, 'copy']);
    Route::post('roles/{role}/archive', [RoleController::class, 'archive']);
    Route::get('permissions', PermissionCatalogueController::class);
    Route::get('users/{user}/assignments', [AssignmentController::class, 'index']);
    Route::post('users/{user}/assignments', [AssignmentController::class, 'store']);
    Route::delete('assignments/{assignment}', [AssignmentController::class, 'destroy']);
    Route::get('access-review', AccessReviewController::class);
});
