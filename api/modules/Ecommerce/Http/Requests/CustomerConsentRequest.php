<?php

namespace Modules\Ecommerce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ecommerce\Customers\MarketingConsent;

/** PUT shop/{store}/account/marketing-consent (ECOM-08, NFR-08): turn marketing messages on, with the wording shown, or off. */
class CustomerConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The customer's session is checked by the controller (401 customer_session_required).
        return true;
    }

    public function rules(): array
    {
        return [
            'granted' => ['required', 'boolean'],
            'wording_version' => ['required_if_accepted:granted', 'nullable', 'string', Rule::in(MarketingConsent::wordingVersions())],
        ];
    }
}
