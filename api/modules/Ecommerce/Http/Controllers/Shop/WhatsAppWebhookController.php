<?php

namespace Modules\Ecommerce\Http\Controllers\Shop;

use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\Ecommerce\Http\Requests\WhatsAppWebhookRequest;
use Modules\Ecommerce\WhatsApp\Inbox;

/**
 * ECOM-24: POST /api/v1/shop/whatsapp/{token}, a store's inbound WhatsApp
 * webhook. The token (40 characters, stored as sha256) names the store;
 * the provider driver verifies the request. Unknown, archived or inactive:
 * 404. Answers `{received: n}`; a repeated message is stored once.
 */
class WhatsAppWebhookController
{
    public function __invoke(WhatsAppWebhookRequest $request, string $token, Inbox $inbox, TenantContext $tenants): JsonResponse
    {
        try {
            $store = $inbox->storeFor($token);
            abort_if($store === null, 404);

            return response()->json(['data' => ['received' => $inbox->receive($store, $request)]]);
        } finally {
            $tenants->set(null);
        }
    }
}
