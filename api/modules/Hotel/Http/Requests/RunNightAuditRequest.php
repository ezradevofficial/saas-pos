<?php

namespace Modules\Hotel\Http\Requests;

/** POST hotel/night-audits (HOT-30): `hotel.audit.run` at the branch. `{branch_id, business_date, close_early?}`: audits the named business date (required, so a repeat or a double click cannot audit the next day; a date already audited answers its audit, 200). A day whose audit time has not passed is refused unless `close_early` is sent (audited). */
class RunNightAuditRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.audit.run';
    }

    protected function branches(): array
    {
        return ['branch_id' => 'hotel.audit.run'];
    }

    public function rules(): array
    {
        return ['branch_id' => HotelRules::branch(), 'business_date' => HotelRules::day(), 'close_early' => ['sometimes', 'boolean']];
    }
}
