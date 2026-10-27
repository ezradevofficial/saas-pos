<?php

namespace Modules\FuelStation\Support;

use Modules\FuelStation\Models\Station;

/**
 * FUEL-02: the station at a location (a forecourt), read under RLS: another
 * tenant's location is not found. Memoised per request.
 */
class Stations
{
    /** @var array<string, ?Station> */
    private array $cache = [];

    public function at(string $locationId): ?Station
    {
        return $this->cache[$locationId] ??= Station::query()->where('location_id', $locationId)->whereNull('archived_at')->first();
    }

    /** The active station at the location or 422 `not_a_station`. */
    public function require(string $locationId, string $field = 'location_id'): Station
    {
        return $this->at($locationId) ?? throw FuelError::make(422, 'not_a_station', field: $field);
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
