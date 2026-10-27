<?php

namespace Modules\Salon\Support;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\Staff;

/**
 * RBAC-04, SAL-28: who may touch which appointment. A reader with `salon.appointment.view` at the place sees every
 * appointment there; a stylist with only `salon.appointment.view_own` sees and serves the appointments they are staff on.
 */
class Access
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    /** The active staff record of $user in the company, if any. */
    public function staffOf(User $user, string $companyId): ?Staff
    {
        return Staff::query()->where('company_id', $companyId)->where('user_id', $user->id)->whereNull('archived_at')->first();
    }

    /** Whether $user may read or serve $appointment: all at the place, or their own. */
    public function touches(User $user, Appointment $appointment, string $allPermission = 'salon.appointment.view'): bool
    {
        if ($this->resolver->can($user, $allPermission, $appointment->scope())) {
            return true;
        }

        $staff = $this->staffOf($user, $appointment->company_id);

        return $staff !== null && Line::query()->where('appointment_id', $appointment->id)->where('staff_id', $staff->id)->exists();
    }
}
