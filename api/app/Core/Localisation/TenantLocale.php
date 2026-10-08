<?php

namespace App\Core\Localisation;

use App\Core\Localisation\Http\SetLocale;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;

/**
 * The current tenant's language (`tenants.default_locale`, `en` when unset
 * or unsupported). Defaults the platform creates for a tenant (units,
 * payment methods, tax codes from its country pack) are named once in this
 * language from the translation files, then belong to the tenant to rename
 * (MD-02; platform-core-spec Conventions).
 */
final class TenantLocale
{
    public const FALLBACK = 'en';

    public function __construct(private readonly TenantContext $context) {}

    public function current(): string
    {
        $id = $this->context->id();
        $locale = $id === null ? null : Tenant::whereKey($id)->value('default_locale');

        return in_array($locale, SetLocale::SUPPORTED, true) ? $locale : self::FALLBACK;
    }
}
