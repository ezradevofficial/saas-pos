<?php

namespace Modules\Hotel\Http\Requests;

/** POST hotel/night-audits (HOT-30): `hotel.audit.run` at the branch. `{branch_id, business_date?}`: audits the property's current business date (idempotent: a date already audited answers its audit, 200). */
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
        return ['branch_id' => HotelRules::branch(), 'business_date' => HotelRules::day(false)];
    }
}
