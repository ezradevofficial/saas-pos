<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Support\Access;

/** POST hotel/folios/{hotel_folio}/transfers (HOT-25): `hotel.folio.transfer` at both folios' branches. `{to_folio_id, line_ids: [line], reason}`; same company and currency. */
class TransferFolioRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.folio.transfer';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_folio')?->branch_id;
    }

    public function rules(): array
    {
        return [
            'to_folio_id' => HotelRules::id('hotel_folios'),
            'line_ids' => ['required', 'array', 'min:1', 'max:100'],
            'line_ids.*' => ['uuid', 'distinct'],
            'reason' => HotelRules::reason(),
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                app(Access::class)->require($this->user(), 'hotel.folio.transfer', (string) Folio::query()->whereKey($this->input('to_folio_id'))->value('branch_id'));
            }
        }];
    }
}
