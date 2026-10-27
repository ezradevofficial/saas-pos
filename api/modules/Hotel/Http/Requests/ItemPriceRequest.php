<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Support\Access;

/** GET hotel/item-price (HOT-21): `hotel.folio.charge` at the folio's branch. `?folio_id=&item_id=&qty?`: the item's price from master data for that folio (null when the price list has none), and whether the user may charge another. */
class ItemPriceRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.folio.charge';
    }

    public function rules(): array
    {
        return [
            'folio_id' => HotelRules::id('hotel_folios'),
            'item_id' => ['required', 'uuid', Rule::exists('items', 'id')->whereNull('archived_at')],
            'qty' => ['sometimes', 'numeric', 'gt:0', 'max:100000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                app(Access::class)->require($this->user(), 'hotel.folio.charge', (string) Folio::query()->whereKey($this->input('folio_id'))->value('branch_id'));
            }
        }];
    }
}
