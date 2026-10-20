<?php

namespace App\Core\Webhooks\Http\Requests;

use App\Core\Webhooks\Models\WebhookDelivery;

/** HOOK-01: one delivery of the log (show, replay); another tenant's is not found (row-level security). */
class WebhookDeliveryRequest extends WebhookRequest
{
    public function delivery(): WebhookDelivery
    {
        return $this->route('webhook_delivery');
    }
}
