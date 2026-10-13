<?php

namespace Modules\Inventory\Stock;

use App\Core\Tenancy\Models\Location;

/**
 * TEN-04: where a location sits (its branch and company), read under RLS:
 * another tenant's location is not found. Inventory keeps company and
 * branch on its documents so lists filter without joins.
 */
class Places
{
    /** @var array<string, ?array{company_id: string, branch_id: string, archived: bool}> */
    private array $cache = [];

    /** @return array{company_id: string, branch_id: string, archived: bool}|null */
    public function of(string $locationId): ?array
    {
        if (array_key_exists($locationId, $this->cache)) {
            return $this->cache[$locationId];
        }

        $row = Location::query()->join('branches', 'branches.id', '=', 'locations.branch_id')
            ->where('locations.id', $locationId)
            ->first(['branches.company_id', 'locations.branch_id', 'locations.archived_at']);

        return $this->cache[$locationId] = $row === null ? null : [
            'company_id' => (string) $row->company_id,
            'branch_id' => (string) $row->branch_id,
            'archived' => $row->archived_at !== null,
        ];
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
