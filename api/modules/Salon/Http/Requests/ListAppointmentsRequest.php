<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;

/** GET salon/appointments and the calendar: `salon.appointment.view`, or `view_own` for one's own. */
class ListAppointmentsRequest extends ViewRequest
{
    public function authorize(): bool
    {
        $resolver = app(ScopeResolver::class);

        return $resolver->can($this->user(), 'salon.appointment.view') || $resolver->can($this->user(), 'salon.appointment.view_own');
    }

    protected function target(): ?Scope
    {
        $target = parent::target();
        $resolver = app(ScopeResolver::class);

        if ($target !== null && ! $resolver->can($this->user(), 'salon.appointment.view', $target) && ! $resolver->can($this->user(), 'salon.appointment.view_own', $target)) {
            throw new AuthorizationException(__('rbac.forbidden'));
        }

        return null;
    }
}
