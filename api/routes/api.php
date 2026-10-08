<?php

use App\Core\CountryPacks\Http\Controllers\CountryPackController;
use App\Core\Currency\Http\Controllers\CompanyCurrencyController;
use App\Core\Currency\Http\Controllers\CurrencyController;
use App\Core\Currency\Http\Controllers\ExchangeRateController;
use App\Core\Currency\Http\Controllers\TenantCurrencyController;
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
use App\Core\MasterData\Dimensions\Dimensions;
use App\Core\MasterData\Dimensions\Http\Controllers\DimensionController;
use App\Core\MasterData\History\Http\HistoryController;
use App\Core\MasterData\Items\Http\Controllers\ItemCategoryController;
use App\Core\MasterData\Items\Http\Controllers\ItemController;
use App\Core\MasterData\Items\Http\Controllers\ItemImageController;
use App\Core\MasterData\Items\Http\Controllers\MediaController;
use App\Core\MasterData\Items\Http\Controllers\UomController;
use App\Core\MasterData\Parties\Http\Controllers\PartyController;
use App\Core\MasterData\PaymentMethods\Http\Controllers\PaymentMethodController;
use App\Core\MasterData\Sharing\Http\MasterDataSettingsController;
use App\Core\MasterData\Taxes\Http\Controllers\PriceListController;
use App\Core\MasterData\Taxes\Http\Controllers\TaxCategoryController;
use App\Core\MasterData\Taxes\Http\Controllers\TaxCodeController;
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
use App\Core\Tenancy\Http\Controllers\TenantSettingsController;
use App\Core\Tenancy\Http\EnsureDeviceToken;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Route keys are UUIDs: anything else is not found, never a database error.
foreach (['company', 'branch', 'location', 'device', 'user', 'role', 'invitation', 'assignment', 'tenant_currency', 'tax_code', 'tax_category', 'price_list', 'party', 'record', 'item', 'item_category', 'uom', 'item_image', 'payment_method', ...array_keys(Dimensions::TYPES)] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

// MD-05: one controller serves every kind of dimension, so its routes bind the model explicitly.
foreach (Dimensions::TYPES as $parameter => $model) {
    Route::model($parameter, $model);
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

// MD-02: an item image behind a temporary signed URL (ItemImages::url). The
// signature is the credential; the controller enters the file's tenant and
// checks the signed-for user may still view the item.
Route::get('media/{path}', MediaController::class)->where('path', 'tenants/.+')->middleware(['throttle:media', 'signed'])->name('media.show');

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

    // AUTH-02, AUTH-09, L10N-01: the tenant's settings (core.settings.edit, tenant scope).
    Route::get('tenant/settings', [TenantSettingsController::class, 'show']);
    Route::patch('tenant/settings', [TenantSettingsController::class, 'update']);
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

    // CUR-01, CUR-02: the ISO catalogue, the tenant's currencies (edited at
    // tenant scope) and each company's base and reporting currencies.
    Route::get('currencies', [CurrencyController::class, 'index']);
    Route::get('tenant/currencies', [TenantCurrencyController::class, 'index']);
    Route::post('tenant/currencies', [TenantCurrencyController::class, 'store']);
    Route::patch('tenant/currencies/{tenant_currency}', [TenantCurrencyController::class, 'update']);
    Route::get('companies/{company}/currencies', [CompanyCurrencyController::class, 'show']);
    Route::put('companies/{company}/currencies', [CompanyCurrencyController::class, 'update']);

    // CUR-03, CUR-07: each company's rate history, shop rates (tolerance
    // alerts) and the rates in force.
    Route::get('companies/{company}/exchange-rates', [ExchangeRateController::class, 'index']);
    Route::post('companies/{company}/exchange-rates', [ExchangeRateController::class, 'store']);
    Route::get('companies/{company}/exchange-rates/current', [ExchangeRateController::class, 'current']);

    // CP-01, CP-03: the published country packs (global, read-only).
    Route::get('country-packs', [CountryPackController::class, 'index']);
    Route::get('country-packs/{country_pack}', [CountryPackController::class, 'show'])->where('country_pack', '[A-Z]{2}');

    // MD-03, CP-02: tax codes with effective-dated rates, copied from the
    // company's country pack; tax categories; price lists.
    Route::get('companies/{company}/tax-codes', [TaxCodeController::class, 'index']);
    Route::post('companies/{company}/tax-codes', [TaxCodeController::class, 'store']);
    Route::post('companies/{company}/tax-codes/apply-pack', [TaxCodeController::class, 'applyPack']);
    Route::get('tax-codes/{tax_code}', [TaxCodeController::class, 'show']);
    Route::patch('tax-codes/{tax_code}', [TaxCodeController::class, 'update']);
    Route::post('tax-codes/{tax_code}/rates', [TaxCodeController::class, 'storeRate']);
    Route::post('tax-codes/{tax_code}/archive', [TaxCodeController::class, 'archive']);
    Route::post('tax-codes/{tax_code}/restore', [TaxCodeController::class, 'restore']);

    Route::get('tax-categories', [TaxCategoryController::class, 'index']);
    Route::post('tax-categories', [TaxCategoryController::class, 'store']);
    Route::get('tax-categories/{tax_category}', [TaxCategoryController::class, 'show']);
    Route::patch('tax-categories/{tax_category}', [TaxCategoryController::class, 'update']);
    Route::post('tax-categories/{tax_category}/archive', [TaxCategoryController::class, 'archive']);
    Route::post('tax-categories/{tax_category}/restore', [TaxCategoryController::class, 'restore']);

    Route::get('companies/{company}/price-lists', [PriceListController::class, 'index']);
    Route::post('companies/{company}/price-lists', [PriceListController::class, 'store']);
    Route::get('price-lists/{price_list}', [PriceListController::class, 'show']);
    Route::patch('price-lists/{price_list}', [PriceListController::class, 'update']);
    Route::post('price-lists/{price_list}/archive', [PriceListController::class, 'archive']);
    Route::post('price-lists/{price_list}/restore', [PriceListController::class, 'restore']);

    // MD-04: payment methods per company, in till order; provider secrets
    // are written here and never returned.
    Route::get('companies/{company}/payment-methods', [PaymentMethodController::class, 'index']);
    Route::post('companies/{company}/payment-methods', [PaymentMethodController::class, 'store']);
    Route::put('companies/{company}/payment-methods/order', [PaymentMethodController::class, 'reorder']);
    Route::get('payment-methods/{payment_method}', [PaymentMethodController::class, 'show']);
    Route::patch('payment-methods/{payment_method}', [PaymentMethodController::class, 'update']);
    Route::post('payment-methods/{payment_method}/archive', [PaymentMethodController::class, 'archive']);
    Route::post('payment-methods/{payment_method}/restore', [PaymentMethodController::class, 'restore']);

    // MD-05: departments, cost centres and projects per company (`dimension_type` names the kind).
    foreach (Dimensions::TYPES as $type => $model) {
        $path = $model::path();
        Route::get("companies/{company}/{$path}", [DimensionController::class, 'index'])->defaults('dimension_type', $type);
        Route::post("companies/{company}/{$path}", [DimensionController::class, 'store'])->defaults('dimension_type', $type);
        Route::get("{$path}/{{$type}}", [DimensionController::class, 'show'])->defaults('dimension_type', $type);
        Route::patch("{$path}/{{$type}}", [DimensionController::class, 'update'])->defaults('dimension_type', $type);
        Route::post("{$path}/{{$type}}/archive", [DimensionController::class, 'archive'])->defaults('dimension_type', $type);
        Route::post("{$path}/{{$type}}/restore", [DimensionController::class, 'restore'])->defaults('dimension_type', $type);
    }

    // MD-01, MD-06: parties (customers, suppliers, contacts, employee
    // links), shared or per company (TEN-08), with duplicate warnings.
    Route::get('parties', [PartyController::class, 'index']);
    Route::post('parties', [PartyController::class, 'store']);
    Route::get('parties/{party}', [PartyController::class, 'show']);
    Route::patch('parties/{party}', [PartyController::class, 'update']);
    Route::post('parties/{party}/archive', [PartyController::class, 'archive']);
    Route::post('parties/{party}/restore', [PartyController::class, 'restore']);

    // MD-02: units of measure (the tenant's), item categories and items,
    // shared or per company (TEN-08), with barcodes and images.
    Route::get('uoms', [UomController::class, 'index']);
    Route::post('uoms', [UomController::class, 'store']);
    Route::get('uoms/{uom}', [UomController::class, 'show']);
    Route::patch('uoms/{uom}', [UomController::class, 'update']);
    Route::post('uoms/{uom}/archive', [UomController::class, 'archive']);
    Route::post('uoms/{uom}/restore', [UomController::class, 'restore']);

    Route::get('item-categories', [ItemCategoryController::class, 'index']);
    Route::post('item-categories', [ItemCategoryController::class, 'store']);
    Route::get('item-categories/{item_category}', [ItemCategoryController::class, 'show']);
    Route::patch('item-categories/{item_category}', [ItemCategoryController::class, 'update']);
    Route::post('item-categories/{item_category}/archive', [ItemCategoryController::class, 'archive']);
    Route::post('item-categories/{item_category}/restore', [ItemCategoryController::class, 'restore']);

    Route::get('items', [ItemController::class, 'index']);
    Route::post('items', [ItemController::class, 'store']);
    Route::get('items/{item}', [ItemController::class, 'show']);
    Route::patch('items/{item}', [ItemController::class, 'update']);
    Route::post('items/{item}/archive', [ItemController::class, 'archive']);
    Route::post('items/{item}/restore', [ItemController::class, 'restore']);
    Route::post('items/{item}/images', [ItemImageController::class, 'store']);
    Route::put('items/{item}/images/order', [ItemImageController::class, 'reorder']);
    Route::delete('item-images/{item_image}', [ItemImageController::class, 'destroy']);

    // TEN-08: shared or per-company master data, per data type.
    Route::get('master-data/settings', [MasterDataSettingsController::class, 'show']);
    Route::put('master-data/settings', [MasterDataSettingsController::class, 'update']);

    // MD-07: a record's change history (party, tax_code, company, ...).
    Route::get('history/{type}/{record}', HistoryController::class)->where('type', '[a-z_]{1,40}');

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
