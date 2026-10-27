<?php

namespace Modules\Salon\Http\Requests;

/** GET salon/companies/{company}/settings: anyone with a salon permission in the company (the till reads them through the pull). */
class ShowSettingsRequest extends SalonRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $this->places()->inCompany($this->user(), 'salon.settings.view', $company->id) || $this->places()->inCompany($this->user(), 'salon.appointment.view', $company->id)
            || $this->places()->inCompany($this->user(), 'salon.service.view', $company->id);
    }
}
