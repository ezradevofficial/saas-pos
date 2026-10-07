<?php

namespace App\Core\Tenancy\Http\Controllers;

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
}
