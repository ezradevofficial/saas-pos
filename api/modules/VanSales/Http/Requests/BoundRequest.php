<?php

namespace Modules\VanSales\Http\Requests;

use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A request on one bound record (a van, route, load, run, collection, count
 * or credit-reviewed sale): the route's permission at that record's scope.
 * Another tenant's record is not found (RLS), so never confirmed.
 */
class BoundRequest extends VanSalesRequest
{
    /** @var list<string> route parameters that hold the record, first one found is checked */
    private const PARAMETERS = ['vs_van', 'vs_route', 'vs_load', 'vs_run', 'vs_collection', 'vs_count', 'vs_sale'];

    protected function target(): ?Scope
    {
        foreach (self::PARAMETERS as $name) {
            $model = $this->route($name);

            if ($model instanceof HasScope) {
                return $model->scope();
            }
        }

        return null;
    }

    public function after(): array
    {
        return [function ($validator) {
            // Checked even when the body is invalid: the record is the route's, not the body's.
            $target = $this->target();

            if ($target !== null && ! app(ScopeResolver::class)->can($this->user(), $this->permission(), $target)) {
                throw new AuthorizationException(__('rbac.forbidden'));
            }
        }, ...parent::after()];
    }
}
