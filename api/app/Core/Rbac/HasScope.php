<?php

namespace App\Core\Rbac;

/**
 * A model whose permission checks apply at a scope (RBAC-04), so
 * `$user->can('core.branch.edit', $branch)` is answered for that branch.
 */
interface HasScope
{
    public function scope(): Scope;
}
