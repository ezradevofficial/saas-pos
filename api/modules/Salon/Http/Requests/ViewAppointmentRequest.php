<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Salon\Support\Access;

/** GET salon/appointments/{id} and its checkout: `salon.appointment.view` at the appointment, or `view_own` for an appointment one is staff on. */
class ViewAppointmentRequest extends SalonRequest
{
    public function authorize(): bool
    {
        $resolver = app(ScopeResolver::class);

        return $resolver->can($this->user(), 'salon.appointment.view') || $resolver->can($this->user(), 'salon.appointment.view_own');
    }

    public function after(): array
    {
        return [function () {
            $appointment = $this->route('salon_appointment');

            if (! app(Access::class)->touches($this->user(), $appointment, 'salon.appointment.view')) {
                throw new AuthorizationException(__('rbac.forbidden'));
            }
        }];
    }
}
