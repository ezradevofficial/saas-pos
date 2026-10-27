<?php

namespace Modules\Hotel\Http\Requests;

/** GET hotel/night-audits/{hotel_audit} (HOT-30): `hotel.audit.view` at the audit's branch. */
class ShowNightAuditRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.audit.view';
    }

    protected function branchId(): ?string
    {
        return $this->bound('hotel_audit')?->branch_id;
    }

    public function rules(): array
    {
        return [];
    }
}
