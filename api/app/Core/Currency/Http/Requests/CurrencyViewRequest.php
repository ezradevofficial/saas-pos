<?php

namespace App\Core\Currency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CUR-01: the catalogue and the tenant's currencies are read by anyone
 * holding `core.currency.view` at any scope (prices and tills need them).
 */
class CurrencyViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('core.currency.view') === true;
    }

    public function rules(): array
    {
        return [];
    }
}
