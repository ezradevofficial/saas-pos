<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;

/** GET salon/commissions and tips: `salon.commission.view`, or `view_own` for one's own. */
class OwnCommissionsRequest extends ViewRequest
{
    public function authorize(): bool
    {
        $resolver = app(ScopeResolver::class);

        return $resolver->can($this->user(), 'salon.commission.view') || $resolver->can($this->user(), 'salon.commission.view_own');
    }

    protected function target(): ?Scope
    {
        return null;
    }
}
