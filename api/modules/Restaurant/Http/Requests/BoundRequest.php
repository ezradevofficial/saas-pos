<?php

namespace Modules\Restaurant\Http\Requests;

use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A request on one bound record (an area, table, station, modifier group,
 * order, line or ticket): the route's permission at that record's scope.
 * Another tenant's record is not found (RLS), so never confirmed.
 */
class BoundRequest extends RestaurantRequest
{
    /** @var list<string> route parameters that hold the record, first one found is checked */
    private const PARAMETERS = ['rest_order', 'rest_ticket', 'rest_area', 'rest_table', 'rest_station', 'rest_modifier_group'];

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
