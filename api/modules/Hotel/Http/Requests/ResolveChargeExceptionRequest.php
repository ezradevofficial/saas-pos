<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Hotel\Models\ChargeException;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Support\Access;

/** POST hotel/charge-exceptions/{hotel_exception}/resolve (HOT-22): `hotel.charge.resolve` at the exception's branch. `{resolution: moved|billed_other|written_off, folio_id? (moved), note?}`; moving posts the charge to an open folio of the same company and currency. */
class ResolveChargeExceptionRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.charge.resolve';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_exception')?->branch_id;
    }

    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', Rule::in(ChargeException::RESOLUTIONS)],
            'folio_id' => ['required_if:resolution,moved', 'nullable', 'uuid', Rule::exists('hotel_folios', 'id')],
            'note' => ['required_unless:resolution,moved', 'nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            if ($validator->errors()->isEmpty() && $this->filled('folio_id')) {
                app(Access::class)->require($this->user(), 'hotel.folio.charge', (string) Folio::query()->whereKey($this->input('folio_id'))->value('branch_id'));
            }
        }];
    }
}
