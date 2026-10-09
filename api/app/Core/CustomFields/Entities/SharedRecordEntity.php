<?php

namespace App\Core\CustomFields\Entities;

use App\Core\Identity\Models\User;
use App\Core\MasterData\CompanyReach;
use App\Core\MasterData\SharedRecordPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Master data shared across the group or kept per company (TEN-08): who
 * sees a record is its SharedRecordPolicy's answer (RBAC-04).
 */
abstract class SharedRecordEntity extends CustomFieldEntity
{
    abstract protected function policy(): SharedRecordPolicy;

    /** The permission prefix, e.g. `core.item`. */
    abstract protected function permission(): string;

    public function viewAny(User $actor): bool
    {
        return $this->policy()->viewAny($actor);
    }

    public function view(User $actor, Model $record): bool
    {
        return $this->policy()->view($actor, $record);
    }

    public function writeAny(User $actor): bool
    {
        return app(CompanyReach::class)->anywhere($actor, ["{$this->permission()}.create", "{$this->permission()}.edit"]);
    }

    public function visible(Builder $query, User $actor): Builder
    {
        $companies = $this->policy()->listableCompanies($actor);

        return $companies === null ? $query : $query->where(fn (Builder $q) => $q->whereNull($q->qualifyColumn('company_id'))->orWhereIn($q->qualifyColumn('company_id'), $companies));
    }
}
