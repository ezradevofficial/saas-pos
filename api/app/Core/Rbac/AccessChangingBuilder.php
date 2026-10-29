<?php

namespace App\Core\Rbac;

use Illuminate\Database\Eloquent\Builder;

/**
 * RBAC-12, NFR-02: the query builder of the models that decide access (role
 * assignments, the tenant's modules). A bulk update or delete skips model
 * events, so without this it would leave cached access (AccessVersion) in
 * place for minutes: someone stripped of a role kept using it. Every bulk
 * write through these models moves the tenant's access version on.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class AccessChangingBuilder extends Builder
{
    public function update(array $values)
    {
        return tap(parent::update($values), fn () => $this->bump());
    }

    public function delete()
    {
        return tap(parent::delete(), fn () => $this->bump());
    }

    public function forceDelete()
    {
        return tap(parent::forceDelete(), fn () => $this->bump());
    }

    public function insert(array $values)
    {
        return tap($this->toBase()->insert($values), fn () => $this->bump());
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        return tap(parent::upsert($values, $uniqueBy, $update), fn () => $this->bump());
    }

    private function bump(): void
    {
        app(AccessVersion::class)->bump();
    }
}
