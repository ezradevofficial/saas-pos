<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A request on one bound record (a service, staff member, appointment, batch...): the route's permission at that
 * record's scope, checked even when the body is invalid (the record is the route's, not the body's). Another tenant's
 * record is not found (RLS), so never confirmed. A subclass adds rules for its body.
 */
class BoundRequest extends SalonRequest
{
    /** @var list<string> route parameters that hold the record, the first one found is checked */
    private const PARAMETERS = [
        'salon_service', 'salon_ptype', 'salon_staff', 'salon_resource', 'salon_appointment', 'salon_queue', 'salon_package', 'salon_exception',
        'salon_rule', 'salon_batch', 'salon_payment', 'salon_timeoff',
    ];

    protected function target(): ?Scope
    {
        foreach (self::PARAMETERS as $name) {
            $model = $this->route($name);

            if ($model instanceof HasScope) {
                return $model->scope();
            }

            // A payment or time off belongs to a record that has the scope.
            if ($model !== null && method_exists($model, 'recordScope')) {
                return $model->recordScope();
            }
        }

        return null;
    }

    public function after(): array
    {
        return [function ($validator) {
            $target = $this->target();

            if ($target !== null && ! app(ScopeResolver::class)->can($this->user(), $this->permission(), $target)) {
                throw new AuthorizationException(__('rbac.forbidden'));
            }
        }, ...parent::after()];
    }
}
