<?php

namespace App\Core\Tenancy\Http\Controllers;

use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * RBAC-04: a record outside the user's scope is not found (404), never
 * forbidden, so its existence is not confirmed; a visible record the user
 * may not change is forbidden (403).
 */
trait ChecksScope
{
    protected function visibleOr404(Request $request, Model $model): void
    {
        abort_unless($request->user()->can('view', $model), 404);
    }

    protected function authorizeInScope(Request $request, string $ability, Model $model): void
    {
        $this->visibleOr404($request, $model);

        abort_unless($request->user()->can($ability, $model), 403);
    }

    /** TEN-06: nothing new is created under an archived record. */
    protected function ensureActiveParent(Company|Branch|Location $parent): void
    {
        if ($parent->isArchived()) {
            throw new ApiException(422, 'parent_archived', __('core.organisation.parent_archived'));
        }
    }
}
