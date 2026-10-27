<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Hotel\Support\Access;

/** GET hotel/night-audits (HOT-30): `hotel.audit.view` at the reader's branches. `?branch=` (the property), `?from=`, `?to=`; newest first, with the property's business date. */
class ListNightAuditsRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.audit.view';
    }

    public function rules(): array
    {
        return [
            'branch' => ['required', 'uuid', Rule::exists('branches', 'id')],
            'from' => HotelRules::day(false),
            'to' => HotelRules::day(false),
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isEmpty()) {
                app(Access::class)->require($this->user(), 'hotel.audit.view', (string) $this->input('branch'));
            }
        }];
    }
}
