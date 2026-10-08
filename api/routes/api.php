<?php

use App\Core\Approvals\EmailApprovals;
use App\Core\Approvals\Http\Controllers\ApprovalController;
use App\Core\Approvals\Http\Controllers\AttachmentFileController;
use App\Core\Approvals\Http\Controllers\DelegationController;
use App\Core\Approvals\Http\Controllers\EmailApprovalController;
use App\Core\Approvals\Http\NoReferrer;
use App\Core\Approvals\Models\ApprovalDelegation;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Automation\Http\Controllers\AutomationCatalogueController;
use App\Core\Automation\Http\Controllers\AutomationRuleController;
use App\Core\Automation\Http\Controllers\AutomationRunController;
use App\Core\Automation\Http\Controllers\AutomationTemplateController;
use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Models\AutomationRun;
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
use App\Core\Identity\Pin\Http\Controllers\DevicePinController;
use App\Core\Identity\Pin\Http\Controllers\MyPinController;
use App\Core\Identity\Pin\Http\Controllers\UserPinController;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\MasterData\CreditLimits\CreditLimitChange;
use App\Core\MasterData\CreditLimits\Http\Controllers\CreditLimitChangeController;
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
use App\Core\MasterData\Prices\Http\Controllers\ItemPriceController;
use App\Core\MasterData\Sharing\Http\MasterDataSettingsController;
use App\Core\MasterData\Taxes\Http\Controllers\PriceListController;
use App\Core\MasterData\Taxes\Http\Controllers\TaxCategoryController;
use App\Core\MasterData\Taxes\Http\Controllers\TaxCodeController;
use App\Core\Notifications\Http\Controllers\DeliveryController;
use App\Core\Notifications\Http\Controllers\InboxController;
use App\Core\Notifications\Http\Controllers\NotificationSettingsController;
use App\Core\Notifications\Http\Controllers\PreferenceController;
use App\Core\Notifications\Http\Controllers\TemplateController;
use App\Core\Numbering\Http\Controllers\NumberFormatController;
use App\Core\Rbac\Http\Controllers\AccessReviewController;
use App\Core\Rbac\Http\Controllers\AssignmentController;
use App\Core\Rbac\Http\Controllers\MyPermissionsController;
use App\Core\Rbac\Http\Controllers\PermissionCatalogueController;
use App\Core\Rbac\Http\Controllers\RoleController;
use App\Core\Sync\Http\Controllers\SyncController;
use App\Core\Sync\Http\Controllers\SyncMediaController;
use App\Core\Tenancy\Http\Controllers\BranchController;
use App\Core\Tenancy\Http\Controllers\CompanyController;
use App\Core\Tenancy\Http\Controllers\DeviceController;
use App\Core\Tenancy\Http\Controllers\DevicePairingController;
use App\Core\Tenancy\Http\Controllers\LocationController;
use App\Core\Tenancy\Http\Controllers\TenantSettingsController;
use App\Core\Tenancy\Http\EnsureDeviceToken;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Http\Controllers\BusinessHoursController;
use App\Core\Workflow\Http\Controllers\DocumentTypeController;
use App\Core\Workflow\Http\Controllers\DocumentWorkflowController;
use App\Core\Workflow\Http\Controllers\WorkflowDefinitionController;
use App\Core\Workflow\Http\Controllers\WorkflowInsightsController;
use App\Core\Workflow\Http\Controllers\WorkflowVersionController;
use App\Core\Workflow\Models\WorkflowDefinition;
use App\Core\Workflow\Models\WorkflowVersion;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// Route keys are UUIDs: anything else is not found, never a database error.
foreach (['company', 'branch', 'location', 'device', 'user', 'role', 'invitation', 'assignment', 'tenant_currency', 'tax_code', 'tax_category', 'price_list', 'item_price', 'party', 'record', 'item', 'item_category', 'uom', 'item_image', 'payment_method', 'credit_limit_change', 'workflow', 'workflow_version', 'document', 'notification', 'approval', 'delegation', 'automation_rule', 'automation_run', ...array_keys(Dimensions::TYPES)] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

Route::pattern('document_type', '[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*');
Route::model('workflow', WorkflowDefinition::class);
Route::model('workflow_version', WorkflowVersion::class);
Route::model('automation_rule', AutomationRule::class);
Route::model('automation_run', AutomationRun::class);
Route::model('approval', ApprovalRequest::class);
Route::model('delegation', ApprovalDelegation::class);
Route::model('credit_limit_change', CreditLimitChange::class);

// WF-10: {document_type}/{document} is the document's running flow, else
// its latest; a type of an inactive module, or a document without a flow
// in this tenant (row-level security), is not found.
Route::bind('document', function (string $value, $route) {
    $type = app(DocumentTypeRegistry::class)->find((string) $route->parameter('document_type'));

    return ($type === null ? null : app(WorkflowEngine::class)->current($type->key(), $value)) ?? abort(404);
});

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

// APR-03: an approval attachment behind a temporary signed URL
// (ApprovalPresenter::url). The controller enters the tenant the path
// names and checks the signed-for user may still see the request.
Route::get('approval-files/{path}', AttachmentFileController::class)->where('path', 'tenants/.+')->middleware(['throttle:media', 'signed'])->name('approvals.attachment');

// APR-08: an emailed approve/reject link; the single-use token is the credential.
Route::get('approvals/email/{token}', [EmailApprovalController::class, 'show'])->middleware([NoReferrer::class, 'throttle:'.EmailApprovals::LIMITER])->where('token', '[A-Za-z0-9]{48}');
Route::post('approvals/email/{token}', [EmailApprovalController::class, 'confirm'])->middleware([NoReferrer::class, 'throttle:'.EmailApprovals::LIMITER])->where('token', '[A-Za-z0-9]{48}');

// TEN-05: a POS device exchanges its one-time pairing code for a token.
Route::post('devices/pair', [DevicePairingController::class, 'pair'])->middleware('throttle:device-pair');

// TEN-05: routes for a paired device's token only (ability `device`).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureDeviceToken::class])->group(function () {
    Route::get('devices/me', [DevicePairingController::class, 'me']);

    // NFR-04, ADR 004: master data sync, rate-limited per device.
    // AUTH-06..AUTH-08: staff PIN sign-in, offline attempt reports and manager overrides.
    Route::middleware('throttle:device-sync')->group(function () {
        Route::get('sync/bootstrap', [SyncController::class, 'bootstrap']);
        Route::get('sync/pull', [SyncController::class, 'pull']);
        Route::get('sync/media/{item_image}', SyncMediaController::class);
        Route::post('pos/pin/verify', [DevicePinController::class, 'verify']);
        Route::post('pos/pin/attempts', [DevicePinController::class, 'attempts']);
        Route::post('pos/override', [DevicePinController::class, 'override']);
        Route::post('pos/pin/change', [DevicePinController::class, 'change']);
    });

    // AUTH-06, AUTH-08: device secret rotation, proven at each step, a few times an hour.
    Route::middleware('throttle:device-secret')->group(function () {
        Route::get('sync/device-secret/challenge', [SyncController::class, 'secretChallenge']);
        Route::post('sync/device-secret/rotate', [SyncController::class, 'rotateSecret']);
        Route::post('sync/device-secret/activate', [SyncController::class, 'activateSecret']);
    });
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

    // AUTH-06: the user's own POS PIN and staff card, confirmed with the password (wrong
    // passwords count towards the account lockout, AUTH-10); never returned.
    Route::get('me/pos-pin', [MyPinController::class, 'show']);
    Route::put('me/pos-pin', [MyPinController::class, 'update']);
    Route::delete('me/pos-pin', [MyPinController::class, 'destroy']);

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

    // MD-03 follow-up: item prices per list and unit, effective-dated, with
    // quantity breaks; one or up to 500 at once (all or nothing).
    Route::get('price-lists/{price_list}/prices', [ItemPriceController::class, 'index']);
    Route::post('price-lists/{price_list}/prices', [ItemPriceController::class, 'store']);
    Route::post('price-lists/{price_list}/prices/bulk', [ItemPriceController::class, 'bulk']);
    Route::post('item-prices/{item_price}/archive', [ItemPriceController::class, 'archive']);
    Route::post('item-prices/{item_price}/restore', [ItemPriceController::class, 'restore']);

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

    // MD-01, WF-01, WF-10, WF-11: credit limit change requests, decided
    // through their flow and applied to the party when approved.
    Route::get('credit-limit-changes', [CreditLimitChangeController::class, 'index']);
    Route::post('credit-limit-changes', [CreditLimitChangeController::class, 'store']);
    Route::get('credit-limit-changes/{credit_limit_change}', [CreditLimitChangeController::class, 'show']);
    Route::post('credit-limit-changes/{credit_limit_change}/cancel', [CreditLimitChangeController::class, 'cancel']);
    Route::post('credit-limit-changes/{credit_limit_change}/apply', [CreditLimitChangeController::class, 'apply']);

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
    // AUTH-06: an administrator resets or removes a user's POS PIN (never reads it).
    Route::put('users/{user}/pos-pin', [UserPinController::class, 'update']);
    Route::delete('users/{user}/pos-pin', [UserPinController::class, 'destroy']);
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

    // WF-01..WF-09, APR-09, spec 6.4: document types, flows and their versions.
    Route::get('workflow/document-types', [DocumentTypeController::class, 'index']);
    Route::get('workflows', [WorkflowDefinitionController::class, 'index']);
    Route::post('workflows', [WorkflowDefinitionController::class, 'store']);
    Route::get('workflows/{workflow}', [WorkflowDefinitionController::class, 'show']);
    Route::get('workflows/{workflow}/versions', [WorkflowDefinitionController::class, 'versions']);
    Route::put('workflows/{workflow}/draft', [WorkflowDefinitionController::class, 'updateDraft']);
    Route::post('workflows/{workflow}/discard-draft', [WorkflowDefinitionController::class, 'discardDraft']);
    Route::post('workflows/{workflow}/validate', [WorkflowDefinitionController::class, 'validateDraft']);
    Route::post('workflows/{workflow}/publish', [WorkflowDefinitionController::class, 'publish']);
    Route::post('workflows/{workflow}/rollback', [WorkflowDefinitionController::class, 'rollback']);
    Route::post('workflows/{workflow}/copy', [WorkflowDefinitionController::class, 'copy']);
    Route::post('workflows/{workflow}/restore-default', [WorkflowDefinitionController::class, 'restoreDefault']);
    Route::post('workflows/{workflow}/test', [WorkflowDefinitionController::class, 'test']);
    Route::get('workflow-insights', [WorkflowInsightsController::class, 'index']);
    Route::get('workflow-versions/{workflow_version}', [WorkflowVersionController::class, 'show']);

    // WF-04, WF-08, WF-10, WF-11: a document's flow, by type and document id.
    Route::get('document-workflows/{document_type}/{document}', [DocumentWorkflowController::class, 'show']);
    Route::post('document-workflows/{document_type}/{document}/move', [DocumentWorkflowController::class, 'move']);
    Route::post('document-workflows/{document_type}/{document}/return', [DocumentWorkflowController::class, 'return']);
    Route::post('document-workflows/{document_type}/{document}/cancel', [DocumentWorkflowController::class, 'cancel']);

    // WF-09, APR-05: a company's working hours for time limits.
    Route::get('companies/{company}/business-hours', [BusinessHoursController::class, 'show']);
    Route::put('companies/{company}/business-hours', [BusinessHoursController::class, 'update']);

    // AUTO-01..AUTO-07: automation rules, test mode, the run log, templates
    // and what the editor may offer per document type.
    Route::get('automation/catalogue', AutomationCatalogueController::class);
    Route::get('automation-rules', [AutomationRuleController::class, 'index']);
    Route::post('automation-rules', [AutomationRuleController::class, 'store']);
    Route::post('automation-rules/test', [AutomationRuleController::class, 'testUnsaved']);
    Route::get('automation-rules/{automation_rule}', [AutomationRuleController::class, 'show']);
    Route::patch('automation-rules/{automation_rule}', [AutomationRuleController::class, 'update']);
    Route::post('automation-rules/{automation_rule}/enable', [AutomationRuleController::class, 'enable']);
    Route::post('automation-rules/{automation_rule}/disable', [AutomationRuleController::class, 'disable']);
    Route::post('automation-rules/{automation_rule}/archive', [AutomationRuleController::class, 'archive']);
    Route::post('automation-rules/{automation_rule}/test', [AutomationRuleController::class, 'test']);
    Route::post('automation-rules/{automation_rule}/webhook-secret/rotate', [AutomationRuleController::class, 'rotateSecret']);
    Route::get('automation-runs', [AutomationRunController::class, 'index']);
    Route::get('automation-runs/{automation_run}', [AutomationRunController::class, 'show']);
    Route::get('automation-templates', [AutomationTemplateController::class, 'index']);
    Route::post('automation-templates/use', [AutomationTemplateController::class, 'use']);

    // APR-03, APR-04, APR-06: the approvals inbox, a request's detail and
    // actions (acting needs only being its approver or their delegate),
    // bulk approval, reassignment (core.approval.reassign) and the user's
    // own delegations.
    Route::get('approvals', [ApprovalController::class, 'index']);
    Route::post('approvals/bulk-approve', [ApprovalController::class, 'bulkApprove']);
    Route::get('approvals/document-types', [ApprovalController::class, 'documentTypes']);
    Route::get('approvals/{approval}/reassign-candidates', [ApprovalController::class, 'reassignCandidates']);
    Route::get('approvals/{approval}', [ApprovalController::class, 'show']);
    Route::post('approvals/{approval}/approve', [ApprovalController::class, 'approve']);
    Route::post('approvals/{approval}/reject', [ApprovalController::class, 'reject']);
    Route::post('approvals/{approval}/return', [ApprovalController::class, 'return']);
    Route::post('approvals/{approval}/comment', [ApprovalController::class, 'comment']);
    Route::post('approvals/{approval}/request-info', [ApprovalController::class, 'requestInfo']);
    Route::post('approvals/{approval}/attachments', [ApprovalController::class, 'attach']);
    Route::post('approvals/{approval}/reassign', [ApprovalController::class, 'reassign']);
    Route::get('me/delegations', [DelegationController::class, 'index']);
    Route::get('me/delegation-candidates', [DelegationController::class, 'candidates']);
    Route::post('me/delegations', [DelegationController::class, 'store']);
    Route::post('me/delegations/{delegation}/revoke', [DelegationController::class, 'revoke']);

    // NOT-01: the signed-in user's own inbox (no permission: everyone has one).
    Route::get('notifications', [InboxController::class, 'index']);
    Route::get('notifications/unread-count', [InboxController::class, 'unreadCount']);
    Route::post('notifications/read-all', [InboxController::class, 'readAll']);
    Route::post('notifications/{notification}/read', [InboxController::class, 'read']);
    Route::post('notifications/{notification}/archive', [InboxController::class, 'archive']);

    // NOT-04, NOT-05: the user's own channels and digest per event type.
    Route::get('me/notification-preferences', [PreferenceController::class, 'show']);
    Route::put('me/notification-preferences', [PreferenceController::class, 'update']);

    // NOT-02, NOT-04: event types with the tenant's mandatory channels; the
    // admin's toggles (core.notification_settings.edit).
    Route::get('notification-event-types', [NotificationSettingsController::class, 'eventTypes']);
    Route::put('notification-settings', [NotificationSettingsController::class, 'update']);

    // NOT-03: texts per event type, channel and language. Writes name the
    // template in the body: event type keys are global, never a tenant row.
    Route::get('notification-templates', [TemplateController::class, 'index']);
    Route::put('notification-templates', [TemplateController::class, 'update']);
    Route::post('notification-templates/reset', [TemplateController::class, 'reset']);
    Route::post('notification-templates/preview', [TemplateController::class, 'preview']);
    Route::get('notification-templates/{event_type}', [TemplateController::class, 'show'])
        ->where('event_type', '[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*');

    // NOT-06: the delivery log (core.notification_delivery.view).
    Route::get('notification-deliveries', [DeliveryController::class, 'index']);

    // NUM-01: number formats per document type, for the tenant, a company or a branch.
    Route::get('numbering/formats', [NumberFormatController::class, 'index']);
    Route::put('numbering/formats', [NumberFormatController::class, 'save']);
});
