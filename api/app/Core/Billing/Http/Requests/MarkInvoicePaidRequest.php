<?php

namespace App\Core\Billing\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/** SUB-04: staff (owner, billing) record a bank transfer received for an invoice, with the bank's reference. */
class MarkInvoicePaidRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.billing.manage';

    public function rules(): array
    {
        return ['reference' => ['required', 'string', 'min:3', 'max:100']];
    }
}
