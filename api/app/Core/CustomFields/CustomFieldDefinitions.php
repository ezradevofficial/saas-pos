<?php

namespace App\Core\CustomFields;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * CF-01: the current tenant's custom field definitions of an entity, read
 * under row-level security, once per HTTP request (RequestCache; dropped
 * when a definition is saved).
 */
class CustomFieldDefinitions
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** @return Collection<int, CustomFieldDefinition> active fields, by position then key */
    public function active(string $entity): Collection
    {
        return $this->all($entity)->filter(fn (CustomFieldDefinition $d) => $d->archived_at === null)->values();
    }

    /** @return Collection<int, CustomFieldDefinition> every field, archived included, by position then key */
    public function all(string $entity): Collection
    {
        $tenant = $this->tenants->id();

        if ($tenant === null) {
            return collect();
        }

        return RequestCache::remember("definitions.{$tenant}.{$entity}", fn () => CustomFieldDefinition::query()
            ->where('entity', $entity)->orderBy('position')->orderBy('key')->get());
    }

    /** @return array<string, CustomFieldDefinition> active fields by key */
    public function byKey(string $entity): array
    {
        return $this->active($entity)->keyBy('key')->all();
    }

    /** Drop what this request kept (a definition was saved). */
    public function forget(): void
    {
        RequestCache::forget();
    }
}
