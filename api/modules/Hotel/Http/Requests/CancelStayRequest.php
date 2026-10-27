<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Hotel\Support\Access;

/**
 * POST hotel/stays/{hotel_stay}/cancel (HOT-15): `hotel.reservation.cancel` at the stay's branch. `{reason, waive_charge?}`; the configured
 * charge is posted unless the notice was long enough or it is waived (`hotel.fee.waive`, audited).
 */
class CancelStayRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.reservation.cancel';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_stay')?->branch_id;
    }

    public function rules(): array
    {
        return ['reason' => HotelRules::reason(), 'waive_charge' => ['sometimes', 'boolean']];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            if ($validator->errors()->isEmpty() && $this->boolean('waive_charge') && ! app(Access::class)->can($this->user(), 'hotel.fee.waive', $this->bound('hotel_stay')->branch_id)) {
                $validator->errors()->add('waive_charge', __('hotel.errors.waive_forbidden'));
            }
        }];
    }
}
