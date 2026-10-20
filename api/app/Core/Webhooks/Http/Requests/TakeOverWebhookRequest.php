<?php

namespace App\Core\Webhooks\Http\Requests;

use App\Core\Rbac\OwnerGuard;

/**
 * HOOK-01: the Owner takes over a subscription made by someone else, so it
 * sends as them (and they may change its URL, events and filter). Only an
 * active Owner (RBAC-10), with `core.webhook.manage` at tenant scope.
 */
class TakeOverWebhookRequest extends WebhookRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && app(OwnerGuard::class)->isOwner($this->user());
    }
}
