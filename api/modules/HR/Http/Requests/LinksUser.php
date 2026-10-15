<?php

namespace Modules\HR\Http\Requests;

use App\Core\Rbac\Scope;
use Modules\HR\Support\Errors;

/** For HR requests that may link an employee to a user (create and update). */
trait LinksUser
{
    /**
     * AUTH-12: linking or unlinking a user needs `hr.employee.link` at the
     * employee's place, and nobody links themselves.
     */
    protected function guardLink(Scope $scope, ?string $current): void
    {
        if (! $this->has('user_id') || $this->input('user_id') === $current) {
            return;
        }

        if (! $this->access()->at($this->user(), 'hr.employee.link', $scope)) {
            $this->forbid();
        }

        if ($this->input('user_id') === $this->user()->id) {
            throw Errors::make(422, 'link_self', field: 'user_id');
        }
    }
}
