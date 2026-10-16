<?php

// Loaded by routes/api.php (prefix /api/v1). SUB-02..SUB-07, LIM-05, ADR 016.

use App\Core\Billing\Http\Controllers\BillingInvoiceController;
use App\Core\Billing\Http\Controllers\BillingPaymentMethodController;
use App\Core\Billing\Http\Controllers\PlatformTenantController;
use App\Core\Billing\Http\Controllers\SubscriptionController;
use App\Core\Billing\Models\BillingInvoice;
use App\Core\Billing\Models\BillingPayment;
use App\Core\Billing\Models\BillingPaymentMethod;
use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\Platform\Http\Middleware\EnsurePlatformAccess;
use Illuminate\Support\Facades\Route;

foreach (['billing_invoice', 'billing_payment', 'billing_payment_method'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

Route::model('billing_invoice', BillingInvoice::class);
Route::model('billing_payment', BillingPayment::class);
Route::model('billing_payment_method', BillingPaymentMethod::class);

// The tenant's subscription (people's tokens only). Every route here stays
// open in read-only mode (EnforceSubscription): paying is how it ends.
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class])->prefix('subscription')->group(function () {
    Route::get('status', [SubscriptionController::class, 'status']);
    Route::get('/', [SubscriptionController::class, 'show']);
    Route::post('quote', [SubscriptionController::class, 'quote']);
    Route::post('change', [SubscriptionController::class, 'change']);
    Route::post('pending/cancel', [SubscriptionController::class, 'cancelPending']);
    Route::post('subscribe', [SubscriptionController::class, 'subscribe']);

    Route::get('invoices', [BillingInvoiceController::class, 'index']);
    Route::get('invoices/{billing_invoice}', [BillingInvoiceController::class, 'show']);
    Route::get('invoices/{billing_invoice}/pdf', [BillingInvoiceController::class, 'pdf']);
    Route::post('invoices/{billing_invoice}/pay', [BillingInvoiceController::class, 'pay'])->middleware('throttle:10,1');
    Route::get('payments/{billing_payment}', [BillingInvoiceController::class, 'payment'])->middleware('throttle:60,1');

    Route::get('payment-methods', [BillingPaymentMethodController::class, 'index']);
    Route::post('payment-methods', [BillingPaymentMethodController::class, 'store'])->middleware('throttle:10,1');
    Route::post('payment-methods/card/complete', [BillingPaymentMethodController::class, 'completeCard'])->middleware('throttle:10,1');
    Route::post('payment-methods/{billing_payment_method}/default', [BillingPaymentMethodController::class, 'makeDefault']);
    Route::post('payment-methods/{billing_payment_method}/archive', [BillingPaymentMethodController::class, 'archive']);
});

// Platform console (staff tokens only): tenants, subscriptions, invoices, settings.
Route::middleware(['auth:platform', EnsurePlatformAccess::class])->prefix('platform')->group(function () {
    Route::get('tenants', [PlatformTenantController::class, 'index']);
    Route::get('tenants/{tenant}', [PlatformTenantController::class, 'show'])->whereUuid('tenant');
    Route::post('tenants/{tenant}/trial', [PlatformTenantController::class, 'extendTrial'])->whereUuid('tenant');
    // ADM-01c: suspend and reactivate, with a reason.
    Route::post('tenants/{tenant}/suspend', [PlatformTenantController::class, 'suspend'])->whereUuid('tenant');
    Route::post('tenants/{tenant}/reactivate', [PlatformTenantController::class, 'reactivate'])->whereUuid('tenant');
    Route::post('tenants/{tenant}/grace', [PlatformTenantController::class, 'grace'])->whereUuid('tenant');
    Route::post('invoices/{operator_invoice}/mark-paid', [PlatformTenantController::class, 'markPaid'])->whereUuid('operator_invoice');
    Route::get('billing-settings', [PlatformTenantController::class, 'settings']);
    Route::put('billing-settings', [PlatformTenantController::class, 'updateSettings']);
});
