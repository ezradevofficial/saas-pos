<?php

namespace App\Core\Webhooks\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Webhooks\Models\WebhookSubscription;
use Illuminate\Foundation\Http\FormRequest;

/**
 * HOOK-01: webhooks are tenant-wide: every request needs
 * `core.webhook.manage` at tenant scope (Owner, Admin). A subscription of
 * another tenant is not found (row-level security on route binding), nor
 * is an archived one (TEN-06: kept, no longer managed).
 */
class WebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('core.webhook.manage', Scope::tenant()) === true;
    }

    public function rules(): array
    {
        return [];
    }

    public function subscription(): WebhookSubscription
    {
        $subscription = $this->route('webhook');
        abort_unless($subscription instanceof WebhookSubscription && $subscription->archived_at === null, 404);

        return $subscription;
    }
}
