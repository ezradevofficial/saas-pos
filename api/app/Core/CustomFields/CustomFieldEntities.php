<?php

namespace App\Core\CustomFields;

use App\Core\CustomFields\Entities\CustomFieldEntity;
use App\Core\CustomFields\Entities\LookupTarget;
use Closure;
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
    /** `item`, `party`, or a run-time entity such as `custom_form:petty_cash` (CF-04). */
    public const KEY = '/^[a-z][a-z0-9_]{0,39}(:[a-z][a-z0-9_]{0,29})?\z/';

    /** @var array<string, CustomFieldEntity> */
    private array $entities = [];

    /** @var array<string, LookupTarget> */
    private array $lookups = [];

    /** @var list<Closure(): list<CustomFieldEntity>> */
    private array $sources = [];

    public function register(CustomFieldEntity $entity): void
    {
        $this->assertKey($entity->key());
        $this->entities[$entity->key()] = $entity;
        $this->lookups[$entity->key()] = $entity;
    }

    /**
     * CF-04: entities that exist per tenant (a custom form type's records
     * and lines), answered by $source for the current tenant each time.
     *
     * @param  Closure(): list<CustomFieldEntity>  $source
     */
    public function registerSource(Closure $source): void
    {
        $this->sources[] = $source;
    }

    public function registerLookup(LookupTarget $target): void
    {
        $this->assertKey($target->key());
        $this->lookups[$target->key()] = $target;
    }

    public function find(string $key): ?CustomFieldEntity
    {
        return $this->entities[$key] ?? $this->fromSources()[$key] ?? null;
    }

    public function get(string $key): CustomFieldEntity
    {
        return $this->find($key) ?? throw new InvalidArgumentException("Unknown custom field entity [{$key}].");
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /** @return array<string, CustomFieldEntity> */
    public function all(): array
    {
        return [...$this->entities, ...$this->fromSources()];
    }

    public function lookup(string $key): ?LookupTarget
    {
        return $this->lookups()[$key] ?? null;
    }

    /** @return array<string, LookupTarget> */
    public function lookups(): array
    {
        $lookups = $this->lookups;

        foreach ($this->fromSources() as $key => $entity) {
            if ($entity->isLookupTarget()) {
                $lookups[$key] ??= $entity;
            }
        }

        return $lookups;
    }

    /** @return array<string, CustomFieldEntity> the run-time entities of the current tenant */
    private function fromSources(): array
    {
        $found = [];

        foreach ($this->sources as $source) {
            foreach ($source() as $entity) {
                if (preg_match(self::KEY, $entity->key()) === 1) {
                    $found[$entity->key()] ??= $entity;
                }
            }
        }

        return $found;
    }

    private function assertKey(string $key): void
    {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException("Invalid custom field entity key [{$key}].");
        }
    }
}
