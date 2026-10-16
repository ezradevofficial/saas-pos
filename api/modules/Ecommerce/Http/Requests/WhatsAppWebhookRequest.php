<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST shop/whatsapp/{token} (ECOM-24): a store's inbound WhatsApp webhook.
 * Public: the unguessable token in the URL names the store, and the
 * provider driver verifies the signature and reads the body (Inbox), so
 * nothing is validated here.
 */
class WhatsAppWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
