<?php

namespace App\Core\CustomFields;

use App\Core\CustomFields\Entities\CustomFieldEntity;
use App\Core\CustomFields\Entities\LookupTarget;
use InvalidArgumentException;

/**
 * CF-01, CF-03: the registry of entities that carry custom fields and of
 * the targets a lookup field may point at. Core registers items, parties
 * and users (CustomFieldsServiceProvider); custom forms (CF-04) and
 * modules register their own at boot or at run time:
 *
 *   app(CustomFieldEntities::class)->register(new VehicleRequestEntity);
 *   app(CustomFieldEntities::class)->registerLookup(new EmployeeLookup);
 *
 * Every entity is a lookup target too.
 */
class CustomFieldEntities
{
    public const KEY = '/^[a-z][a-z0-9_]{0,39}\z/';

    /** @var array<string, CustomFieldEntity> */
    private array $entities = [];

    /** @var array<string, LookupTarget> */
    private array $lookups = [];

    public function register(CustomFieldEntity $entity): void
    {
        $this->assertKey($entity->key());
        $this->entities[$entity->key()] = $entity;
        $this->lookups[$entity->key()] = $entity;
    }

    public function registerLookup(LookupTarget $target): void
    {
        $this->assertKey($target->key());
        $this->lookups[$target->key()] = $target;
    }

    public function find(string $key): ?CustomFieldEntity
    {
        return $this->entities[$key] ?? null;
    }

    public function get(string $key): CustomFieldEntity
    {
        return $this->find($key) ?? throw new InvalidArgumentException("Unknown custom field entity [{$key}].");
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->entities);
    }

    /** @return array<string, CustomFieldEntity> */
    public function all(): array
    {
        return $this->entities;
    }

    public function lookup(string $key): ?LookupTarget
    {
        return $this->lookups[$key] ?? null;
    }

    /** @return array<string, LookupTarget> */
    public function lookups(): array
    {
        return $this->lookups;
    }

    private function assertKey(string $key): void
    {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException("Invalid custom field entity key [{$key}].");
        }
    }
}
