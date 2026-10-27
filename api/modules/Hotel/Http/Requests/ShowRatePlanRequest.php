<?php

namespace Modules\Hotel\Http\Requests;

/** GET hotel/rate-plans/{hotel_rate_plan} (HOT-06): `hotel.rate.view` at the plan's company. */
class ShowRatePlanRequest extends HotelRequest
{
    protected function permission(): string
    {
        return 'hotel.rate.view';
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
