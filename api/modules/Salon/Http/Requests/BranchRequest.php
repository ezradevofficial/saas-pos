<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;

/** A request on a branch route parameter: the route's permission at that branch. */
class BranchRequest extends SalonRequest
{
    protected function target(): ?Scope
    {
        return Scope::branch($this->route('branch')->id);
    }
}
