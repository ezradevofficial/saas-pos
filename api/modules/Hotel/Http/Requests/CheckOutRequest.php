<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Hotel\Support\Access;

/**
 * POST hotel/stays/{hotel_stay}/check-out (HOT-19): `hotel.stay.checkout` at the stay's branch. `{payments?: [{payment_method_id, amount_minor, reference?}],
 * waive_fees?, leave_balance?}`: payments are taken (each needs `hotel.folio.payment`), the folio is settled into its invoice and the room made
 * dirty. A balance still owed refuses the check-out unless `leave_balance` is sent and the user holds `hotel.folio.settle_open`; waiving the late fee
 * needs `hotel.fee.waive`.
 */
class CheckOutRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.stay.checkout';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_stay')?->branch_id;
    }

    public function rules(): array
    {
        return [
            'payments' => ['sometimes', 'array', 'max:10'],
            'payments.*.payment_method_id' => HotelRules::id('payment_methods'),
            'payments.*.amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999999'],
            'payments.*.reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'waive_fees' => ['sometimes', 'boolean'],
            'leave_balance' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $access = app(Access::class);
            $branch = $this->bound('hotel_stay')->branch_id;

            foreach ([['payments', 'hotel.folio.payment', ! empty($this->input('payments'))], ['waive_fees', 'hotel.fee.waive', $this->boolean('waive_fees')], ['leave_balance', 'hotel.folio.settle_open', $this->boolean('leave_balance')]] as [$field, $permission, $used]) {
                if ($used && ! $access->can($this->user(), $permission, $branch)) {
                    $validator->errors()->add($field, __('hotel.errors.place_forbidden'));
                }
            }
        }];
    }
}
