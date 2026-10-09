<?php

namespace App\Core\CustomForms;

use App\Core\CustomFields\RequestCache;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * CF-04: the current tenant's custom form types, read under row-level
 * security once per HTTP request (they feed the run-time registries of
 * custom field entities, document types, number types and form layouts).
 * Archived types stay registered: their records, flows and numbers remain.
 */
class CustomFormTypes
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** @return Collection<int, CustomFormType> */
    public function all(): Collection
    {
        $tenant = $this->tenants->id();

        if ($tenant === null) {
            return collect();
        }

        return RequestCache::remember("custom_form_types.{$tenant}", fn () => CustomFormType::query()->orderBy('name')->orderBy('id')->get());
    }

    public function byKey(string $key): ?CustomFormType
    {
        return $this->all()->firstWhere('key', $key);
    }

    /** The type whose document type (WF-01) or entity key this is, if any. */
    public function forDocumentType(string $documentType): ?CustomFormType
    {
        return str_starts_with($documentType, CustomFormType::DOCUMENT_PREFIX) ? $this->byKey(substr($documentType, strlen(CustomFormType::DOCUMENT_PREFIX))) : null;
    }

    /** Drop what this request kept (a type was saved). */
    public function forget(): void
    {
        RequestCache::forget();
    }
}
