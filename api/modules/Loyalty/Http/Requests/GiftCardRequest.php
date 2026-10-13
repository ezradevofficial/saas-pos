<?php

namespace Modules\Loyalty\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Loyalty\Models\GiftCard;

/**
 * Gift cards and vouchers in the back office (LOY-20..LOY-23):
 *
 * - POST loyalty/gift-cards `{company_id, kind, currency, amount_minor, count?, expires_on?, active?, party_id?, reference?}`
 *   issues one or more (`loyalty.gift_card.issue` at the company); the codes are answered once;
 * - POST loyalty/gift-cards/{loyalty_gift_card}/sell `{amount_minor?, reference?}` (`loyalty.gift_card.sell`);
 * - POST loyalty/gift-cards/lookup `{code}`: the balance of a card of a company the user sees (`loyalty.gift_card.view`).
 */
class GiftCardRequest extends LoyaltyRequest
{
    public function authorize(): bool
    {
        $card = $this->route('loyalty_gift_card');

        if ($card !== null) {
            $this->authorizeAt($card->company_id, ['loyalty.gift_card.view'], 'loyalty.gift_card.sell');
        } else {
            $this->authorizeAnywhere(str_ends_with($this->route()->uri(), 'lookup') ? 'loyalty.gift_card.view' : 'loyalty.gift_card.issue');
        }

        return true;
    }

    public function rules(): array
    {
        if ($this->route('loyalty_gift_card') !== null) {
            return [
                'amount_minor' => ['sometimes', ...self::minor(required: false)],
                'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            ];
        }

        if (str_ends_with($this->route()->uri(), 'lookup')) {
            return ['code' => ['required', 'string', 'min:4', 'max:40']];
        }

        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')],
            'kind' => ['required', 'string', Rule::in([GiftCard::GIFT_CARD, GiftCard::VOUCHER])],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}\z/', Rule::exists('tenant_currencies', 'code')->where('active', true)],
            'amount_minor' => self::minor(),
            'count' => ['sometimes', 'integer', 'between:1,'.config('loyalty.max_issue')],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'active' => ['sometimes', 'boolean'],
            'party_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('parties', 'id')->whereNull('archived_at')],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && $this->route('loyalty_gift_card') === null && $this->filled('company_id')) {
                $this->authorizeAt($this->input('company_id'), ['loyalty.gift_card.view', 'loyalty.gift_card.issue'], 'loyalty.gift_card.issue');
            }
        }];
    }
}
