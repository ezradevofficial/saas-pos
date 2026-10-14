<?php

namespace Modules\Stores\Stock;

use App\Core\Tenancy\Models\Location;

/**
 * TEN-04: where a store sits (its branch and company) and whether it is a
 * store at all, read under RLS: another tenant's location is not found.
 */
class Places
{
    /** STR-02: the location types that are stores. */
    public const STORE_TYPES = ['store', 'warehouse'];

    /** @var array<string, ?array{company_id: string, branch_id: string, type: string, archived: bool}> */
    private array $cache = [];

    /** @return array{company_id: string, branch_id: string, type: string, archived: bool}|null */
    public function of(string $locationId): ?array
    {
        if (array_key_exists($locationId, $this->cache)) {
            return $this->cache[$locationId];
        }

        $row = Location::query()->join('branches', 'branches.id', '=', 'locations.branch_id')
            ->where('locations.id', $locationId)
            ->first(['branches.company_id', 'locations.branch_id', 'locations.type', 'locations.archived_at']);

        return $this->cache[$locationId] = $row === null ? null : [
            'company_id' => (string) $row->company_id,
            'branch_id' => (string) $row->branch_id,
            'type' => (string) $row->type,
            'archived' => $row->archived_at !== null,
        ];
    }

    /**
     * A store a document may be made at: known, of a store type and not
     * archived (422 otherwise, on $field).
     *
     * @return array{company_id: string, branch_id: string, type: string, archived: bool}
     */
    public function store(string $locationId, string $field = 'location_id'): array
    {
        $place = $this->of($locationId) ?? throw Errors::make(422, 'location_unknown', field: $field);

        if ($place['archived']) {
            throw Errors::make(422, 'location_archived', field: $field);
        }

        if (! in_array($place['type'], self::STORE_TYPES, true)) {
            throw Errors::make(422, 'not_a_store', field: $field);
        }

        return $place;
    }
}
