<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Salon\Support\Access;

/** POST salon/appointments/{id}/check-in|start|finish (SAL-13): `salon.appointment.serve` at the appointment; a stylist with only their own calendar serves their own. */
class ServeRequest extends BoundRequest
{
    protected function target(): ?Scope
    {
        return null;
    }

    public function after(): array
    {
        return [function () {
            $appointment = $this->route('salon_appointment');

            if (! app(Access::class)->touches($this->user(), $appointment, 'salon.appointment.view')) {
                throw new AuthorizationException(__('rbac.forbidden'));
            }

            if (! app(ScopeResolver::class)->can($this->user(), $this->permission(), $appointment->scope())) {
                throw new AuthorizationException(__('rbac.forbidden'));
            }
        }];
    }
}
