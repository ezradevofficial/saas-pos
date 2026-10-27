<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Hotel\Support\Access;

/** POST hotel/folios/{hotel_folio}/settle (HOT-19, HOT-26): `hotel.stay.checkout` at the folio's branch. Settles a folio with nothing owed (a master folio, a cancelled stay's fee); `{leave_balance?}` needs `hotel.folio.settle_open`. */
class SettleFolioRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.stay.checkout';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_folio')?->branch_id;
    }

    public function rules(): array
    {
        return ['leave_balance' => ['sometimes', 'boolean']];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            if ($validator->errors()->isEmpty() && $this->boolean('leave_balance') && ! app(Access::class)->can($this->user(), 'hotel.folio.settle_open', $this->bound('hotel_folio')->branch_id)) {
                $validator->errors()->add('leave_balance', __('hotel.errors.place_forbidden'));
            }
        }];
    }
}
