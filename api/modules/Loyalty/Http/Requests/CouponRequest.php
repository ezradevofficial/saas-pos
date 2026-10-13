<?php

namespace Modules\Loyalty\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Loyalty\Models\Coupon;
use Modules\Loyalty\Promotions\PromotionEngine;

/**
 * POST loyalty/promotions/{loyalty_promotion}/coupons (LOY-09,
 * `loyalty.promotion.edit`): codes typed (`codes`) and/or generated
 * (`generate`: how many), each with the usage limit `max_uses` (empty:
 * unlimited). Codes are stored upper case without spaces, letters, digits
 * and dashes, unique in the company.
 */
class CouponRequest extends LoyaltyRequest
{
    public function authorize(): bool
    {
        $this->authorizeAt($this->route('loyalty_promotion')->company_id, ['loyalty.promotion.view'], 'loyalty.promotion.edit');

        return true;
    }

    public function rules(): array
    {
        return [
            'codes' => ['sometimes', 'array', 'max:500'],
            'codes.*' => ['string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9 -]*\z/'],
            'generate' => ['sometimes', 'integer', 'between:1,500'],
            'max_uses' => ['sometimes', 'nullable', 'integer', 'between:1,1000000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $this->filled('codes') && ! $this->filled('generate')) {
                $validator->errors()->add('codes', __('loyalty.validation.codes_required'));

                return;
            }

            $codes = $this->codes();

            if (count($codes) !== count(array_unique($codes))) {
                $validator->errors()->add('codes', __('loyalty.validation.codes_distinct'));
            }

            $taken = Coupon::query()->where('company_id', $this->route('loyalty_promotion')->company_id)->whereIn('code', $codes)->pluck('code')->all();

            if ($taken !== []) {
                $validator->errors()->add('codes', __('loyalty.validation.codes_taken', ['codes' => implode(', ', $taken)]));
            }
        }];
    }

    /** @return list<string> the typed codes, normalised */
    public function codes(): array
    {
        return array_map(fn ($code) => PromotionEngine::normaliseCode((string) $code), (array) $this->input('codes', []));
    }
}
