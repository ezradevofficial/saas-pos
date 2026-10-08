<?php

namespace App\Core\Currency\Http\Requests;

use App\Core\Currency\Http\Requests\Concerns\ValidatesPair;

/**
 * CUR-03: the rates in force now. `?pair=USD/CDF` answers that pair (the
 * inverse is computed when only CDF/USD is stored); both currencies must
 * be active in the tenant. Without it, every pair the company has.
 */
class CurrentExchangeRateRequest extends ExchangeRateRequest
{
    use ValidatesPair;

    public function rules(): array
    {
        return [
            'pair' => ['sometimes', 'string', 'regex:/^[A-Z]{3}\/[A-Z]{3}\z/', $this->activePair()],
        ];
    }
}
