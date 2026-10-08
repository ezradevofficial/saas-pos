<?php

namespace App\Core\Currency\Http\Requests;

use App\Core\Currency\Rules\RateValue;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * CUR-03: a shop rate, 1 base = mid quote, with optional buy and sell
 * (buy <= mid <= sell), effective now unless `effective_at` says
 * otherwise. Both currencies are active in the tenant. Needs
 * `core.exchange_rate.override` at the company's scope.
 */
class StoreExchangeRateRequest extends ExchangeRateRequest
{
    protected function allowed(Company $company, bool $reachesForView): bool
    {
        return $this->user()->can('core.exchange_rate.override', $company);
    }

    public function rules(): array
    {
        $active = Rule::exists('tenant_currencies', 'code')->where('active', true);

        return [
            'base' => ['required', 'string', 'regex:/^[A-Z]{3}\z/', $active],
            'quote' => ['required', 'string', 'regex:/^[A-Z]{3}\z/', 'different:base', $active],
            'mid' => ['required', new RateValue],
            'buy' => ['sometimes', 'nullable', new RateValue],
            'sell' => ['sometimes', 'nullable', new RateValue],
            'effective_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $mid = BigDecimal::of((string) $this->input('mid'));

            if ($this->filled('buy') && BigDecimal::of((string) $this->input('buy'))->isGreaterThan($mid)) {
                $validator->errors()->add('buy', __('core.exchange_rate.buy_above_mid'));
            }

            if ($this->filled('sell') && BigDecimal::of((string) $this->input('sell'))->isLessThan($mid)) {
                $validator->errors()->add('sell', __('core.exchange_rate.sell_below_mid'));
            }
        }];
    }

    public function messages(): array
    {
        return [
            'base.exists' => __('core.currency.not_active'),
            'quote.exists' => __('core.currency.not_active'),
            'quote.different' => __('core.exchange_rate.same_currency'),
        ];
    }

    public function attributes(): array
    {
        return [
            'base' => __('core.exchange_rate.attributes.base'),
            'quote' => __('core.exchange_rate.attributes.quote'),
            'mid' => __('core.exchange_rate.attributes.mid'),
            'buy' => __('core.exchange_rate.attributes.buy'),
            'sell' => __('core.exchange_rate.attributes.sell'),
            'effective_at' => __('core.exchange_rate.attributes.effective_at'),
        ];
    }
}
