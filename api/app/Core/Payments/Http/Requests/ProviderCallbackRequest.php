<?php

namespace App\Core\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST payments/callbacks/{token}/{kind}: a payment provider's callback.
 * Public: the unguessable token in the URL is the credential (it names the
 * tenant and the method), the caller's address must be one of the
 * provider's (`payments.mpesa.callback_ips`, checked by the controller),
 * and the body is checked kind by kind (DarajaCallbacks).
 */
class ProviderCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    /** The JSON body (an empty array when it is not an object). */
    public function payload(): array
    {
        $body = $this->json()->all();

        return is_array($body) ? $body : [];
    }
}
