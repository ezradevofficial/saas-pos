<?php

namespace App\Core\Webhooks\Http\Requests;

use App\Core\Webhooks\Models\WebhookDelivery;
use Illuminate\Validation\Rule;

/**
 * HOOK-01: the delivery log, newest first, filtered by subscription
 * (`webhook`, an id of this tenant: another tenant's is not found),
 * `status` and event `type`, paged.
 */
class ListWebhookDeliveriesRequest extends WebhookRequest
{
    public function rules(): array
    {
        return [
            'webhook' => ['sometimes', 'nullable', 'uuid', Rule::exists('webhook_subscriptions', 'id')],
            'status' => ['sometimes', 'nullable', 'string', Rule::in([...WebhookDelivery::STATUSES, 'all'])],
            'type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
