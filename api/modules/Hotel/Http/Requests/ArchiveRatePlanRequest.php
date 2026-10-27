<?php

namespace Modules\Hotel\Http\Requests;

/** POST hotel/rate-plans/{hotel_rate_plan}/archive (HOT-06, TEN-06): `hotel.rate.manage` at the plan's company. */
class ArchiveRatePlanRequest extends HotelRequest
{
    protected function wide(): bool
    {
        return true;
    }

    protected function permission(): string
    {
        return 'hotel.rate.manage';
    }

    protected function companyId(): ?string
    {
        return $this->bound('hotel_rate_plan')?->company_id;
    }

    public function rules(): array
    {
        return [];
    }
}
