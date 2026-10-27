<?php

namespace Modules\Pharmacy\Support;

use App\Core\Tenancy\Models\Location;

/**
 * TEN-04: where a location sits (its branch and company), read under RLS:
 * another tenant's location is not found.
 */
class Places
{
    /** @var array<string, ?array{company_id: string, branch_id: string, archived: bool, name: string}> */
    private array $cache = [];

    /** @return array{company_id: string, branch_id: string, archived: bool, name: string}|null */
    public function of(string $locationId): ?array
    {
        if (array_key_exists($locationId, $this->cache)) {
            return $this->cache[$locationId];
        }

        $row = Location::query()->join('branches', 'branches.id', '=', 'locations.branch_id')
            ->where('locations.id', $locationId)
            ->first(['branches.company_id', 'locations.branch_id', 'locations.archived_at', 'locations.name']);

        return $this->cache[$locationId] = $row === null ? null : [
            'company_id' => (string) $row->company_id,
            'branch_id' => (string) $row->branch_id,
            'archived' => $row->archived_at !== null,
            'name' => (string) $row->name,
        ];
    }

    /** The place or a 422 `location_unknown` / `location_archived`. @return array{company_id: string, branch_id: string, archived: bool, name: string} */
    public function require(string $locationId, string $field = 'location_id'): array
    {
        $place = $this->of($locationId) ?? throw PharmacyError::make(422, 'location_unknown', field: $field);

        if ($place['archived']) {
            throw PharmacyError::make(422, 'location_archived', field: $field);
        }

        return $place;
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
