<?php

namespace App\Core\Sync\Sources;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\IncrementalSource;
use App\Core\Sync\DeviceScope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** MD-02, NFR-04: the tenant's units of measure (shared by every company), active only. */
class UomSource implements IncrementalSource
{
    public function key(): string
    {
        return 'uoms';
    }

    public function module(): string
    {
        return ModuleRegistry::CORE;
    }

    public function version(): int
    {
        return 1;
    }

    public function table(): string
    {
        return 'uoms';
    }

    public function visible(Builder $query, DeviceScope $scope): Builder
    {
        return $query;
    }

    public function rows(array $ids, DeviceScope $scope): array
    {
        return DB::connection(TenantContext::CONNECTION)->table('uoms')
            ->whereIn('id', $ids)
            ->whereNull('archived_at')
            ->get(['id', 'code', 'name', 'kind', 'updated_at'])
            ->mapWithKeys(fn (object $u) => [$u->id => [
                'id' => $u->id,
                'code' => (string) $u->code,
                'name' => $u->name,
                'kind' => $u->kind,
                'updated_at' => Iso::of($u->updated_at),
            ]])
            ->all();
    }
}
