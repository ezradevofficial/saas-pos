<?php

namespace Modules\Loyalty\Http\Requests;

use App\Core\MasterData\Items\Item;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT companies/{company}/loyalty-programme (LOY-01): `loyalty.programme.edit`
 * at the company.
 * The gift card item is one the company sells (its own or shared).
 */
class ProgrammeRequest extends LoyaltyRequest
{
    public function authorize(): bool
    {
        $this->authorizeAt($this->route('company')->id, ['loyalty.programme.view', 'loyalty.programme.edit'], 'loyalty.programme.edit');

        return true;
    }

    public function rules(): array
    {
        return [
            'active' => ['sometimes', 'boolean'],
            'earn_points' => ['sometimes', 'integer', 'between:0,1000'],
            'earn_spend_minor' => ['sometimes', ...self::minor()],
            'point_value_minor' => ['sometimes', ...self::minor()],
            'min_redeem_points' => ['sometimes', 'string', 'regex:/^\d{1,12}\z/'],
            'expiry_months' => ['sometimes', 'nullable', 'integer', 'between:1,120'],
            'expiry_notice_days' => ['sometimes', 'integer', 'between:0,365'],
            'credit_warning_percent' => ['sometimes', 'integer', 'between:1,100'],
            'gift_card_low_minor' => ['sometimes', ...self::minor(required: false, positive: false)],
            'gift_card_item_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('items', 'id')->whereNull('archived_at')],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $itemId = $this->input('gift_card_item_id');

            if ($validator->errors()->isEmpty() && $itemId !== null) {
                $item = Item::query()->find($itemId);

                if ($item->company_id !== null && $item->company_id !== $this->route('company')->id) {
                    $validator->errors()->add('gift_card_item_id', __('loyalty.validation.item_other_company'));
                }
            }
        }];
    }
}
