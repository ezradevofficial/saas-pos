<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use Illuminate\Support\Facades\Route;
use Modules\Ecommerce\Http\Controllers\CashupController;
use Modules\Ecommerce\Http\Controllers\ConversationController;
use Modules\Ecommerce\Http\Controllers\CustomerController;
use Modules\Ecommerce\Http\Controllers\DeliveryController;
use Modules\Ecommerce\Http\Controllers\OrderController;
use Modules\Ecommerce\Http\Controllers\ProductController;
use Modules\Ecommerce\Http\Controllers\ReportController;
use Modules\Ecommerce\Http\Controllers\RiderAppController;
use Modules\Ecommerce\Http\Controllers\RiderController;
use Modules\Ecommerce\Http\Controllers\StoreController;
use Modules\Ecommerce\Http\Controllers\ZoneController;
use Modules\Ecommerce\Models\Conversation;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Product;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Models\Zone;

// E-commerce back office and the rider app (docs/modules/ecommerce.md, API),
// prefix /api/v1, loaded by EcommerceServiceProvider. RBAC-08: every route
// is closed (403 `module_inactive`) unless the tenant has the module
// active. People's tokens only (riders sign in as themselves, decision 7).

$models = [
    'ecom_store' => Store::class,
    'ecom_product' => Product::class,
    'ecom_zone' => Zone::class,
    'ecom_order' => Order::class,
    'ecom_delivery' => Delivery::class,
    'ecom_rider' => Rider::class,
    'ecom_conversation' => Conversation::class,
];

foreach ($models as $parameter => $model) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    Route::model($parameter, $model);
}

Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:ecommerce'])->group(function () {
    // ECOM-01, ECOM-23, ECOM-24: stores, share links, the WhatsApp webhook token.
    Route::get('ecommerce/stores', [StoreController::class, 'index']);
    Route::post('ecommerce/stores', [StoreController::class, 'store']);
    Route::get('ecommerce/stores/{ecom_store}', [StoreController::class, 'show']);
    Route::patch('ecommerce/stores/{ecom_store}', [StoreController::class, 'update']);
    Route::post('ecommerce/stores/{ecom_store}/archive', [StoreController::class, 'archive']);
    Route::post('ecommerce/stores/{ecom_store}/restore', [StoreController::class, 'restore']);
    Route::post('ecommerce/stores/{ecom_store}/whatsapp-token', [StoreController::class, 'whatsappToken']);

    // ECOM-02: the catalogue.
    Route::get('ecommerce/stores/{ecom_store}/products', [ProductController::class, 'index']);
    Route::post('ecommerce/stores/{ecom_store}/products', [ProductController::class, 'store']);
    Route::patch('ecommerce/products/{ecom_product}', [ProductController::class, 'update']);
    Route::post('ecommerce/products/{ecom_product}/archive', [ProductController::class, 'archive']);
    Route::post('ecommerce/products/{ecom_product}/restore', [ProductController::class, 'restore']);

    // ECOM-10: zones and fees.
    Route::get('ecommerce/stores/{ecom_store}/zones', [ZoneController::class, 'index']);
    Route::post('ecommerce/stores/{ecom_store}/zones', [ZoneController::class, 'store']);
    Route::patch('ecommerce/zones/{ecom_zone}', [ZoneController::class, 'update']);
    Route::post('ecommerce/zones/{ecom_zone}/archive', [ZoneController::class, 'archive']);

    // ECOM-09, ECOM-16 to ECOM-22: orders.
    Route::post('ecommerce/stores/{ecom_store}/quote', [OrderController::class, 'quote']);
    Route::get('ecommerce/orders', [OrderController::class, 'index']);
    Route::post('ecommerce/orders', [OrderController::class, 'store']);
    Route::get('ecommerce/orders/{ecom_order}', [OrderController::class, 'show']);
    Route::post('ecommerce/orders/{ecom_order}/accept', [OrderController::class, 'accept']);
    Route::post('ecommerce/orders/{ecom_order}/pick', [OrderController::class, 'pick']);
    Route::post('ecommerce/orders/{ecom_order}/ready', [OrderController::class, 'ready']);
    Route::post('ecommerce/orders/{ecom_order}/dispatch', [OrderController::class, 'dispatch']);
    Route::post('ecommerce/orders/{ecom_order}/deliver', [OrderController::class, 'deliver']);
    Route::post('ecommerce/orders/{ecom_order}/cancel', [OrderController::class, 'cancel']);
    Route::post('ecommerce/orders/{ecom_order}/return', [OrderController::class, 'return']);
    Route::post('ecommerce/orders/{ecom_order}/refund', [OrderController::class, 'refund']);

    // ECOM-27: the delivery board and assignment.
    Route::get('ecommerce/deliveries', [DeliveryController::class, 'index']);
    Route::post('ecommerce/deliveries/{ecom_delivery}/assign', [DeliveryController::class, 'assign']);

    // ECOM-26: riders.
    Route::get('ecommerce/riders', [RiderController::class, 'index']);
    Route::post('ecommerce/riders', [RiderController::class, 'store']);
    Route::patch('ecommerce/riders/{ecom_rider}', [RiderController::class, 'update']);
    Route::post('ecommerce/riders/{ecom_rider}/archive', [RiderController::class, 'archive']);

    // ECOM-30: cash-ups.
    Route::get('ecommerce/cashups', [CashupController::class, 'index']);
    Route::get('ecommerce/cashups/pending', [CashupController::class, 'pending']);
    Route::post('ecommerce/cashups', [CashupController::class, 'store']);

    // ECOM-25: the WhatsApp inbox.
    Route::get('ecommerce/conversations', [ConversationController::class, 'index']);
    Route::get('ecommerce/conversations/{ecom_conversation}', [ConversationController::class, 'show']);
    Route::post('ecommerce/conversations/{ecom_conversation}/reply', [ConversationController::class, 'reply']);
    Route::post('ecommerce/conversations/{ecom_conversation}/close', [ConversationController::class, 'close']);
    Route::post('ecommerce/conversations/{ecom_conversation}/order', [ConversationController::class, 'order']);

    // ECOM-08, ECOM-36: customers and reports.
    Route::get('ecommerce/customers', [CustomerController::class, 'index']);
    Route::get('ecommerce/reports/summary', [ReportController::class, 'summary']);

    // ECOM-28: the rider app.
    Route::get('ecommerce/rider/deliveries', [RiderAppController::class, 'deliveries']);
    Route::post('ecommerce/rider/updates', [RiderAppController::class, 'updates']);
    Route::post('ecommerce/rider/deliveries/{ecom_delivery}/photo', [RiderAppController::class, 'photo']);
});
