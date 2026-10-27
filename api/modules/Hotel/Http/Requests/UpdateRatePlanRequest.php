<?php

namespace Modules\Hotel\Http\Requests;

/** PATCH hotel/rate-plans/{hotel_rate_plan} (HOT-06 to HOT-09): `hotel.rate.manage` at the plan's company; `seasons`, when sent, replaces the plan's seasons. Booked stays keep their quote (HOT-10). */
class UpdateRatePlanRequest extends HotelRequest
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
        return RatePlanFields::rules(updating: true);
    }
}
