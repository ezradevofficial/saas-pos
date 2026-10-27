<?php

namespace Modules\Hotel\Support;

use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;

/**
 * Text stored with a record (a folio line's description, a task note) is
 * written once, in the tenant's default language, never per reader.
 */
class Text
{
    /** @var array<string, string> */
    private static array $locales = [];

    /** @param array<string, string|int> $replace */
    public static function line(string $key, array $replace = []): string
    {
        $tenantId = app(TenantContext::class)->require();
        $locale = self::$locales[$tenantId] ??= (string) (Tenant::query()->whereKey($tenantId)->value('default_locale') ?: 'en');

        return __($key, $replace, $locale);
    }

    public static function forget(): void
    {
        self::$locales = [];
    }
}
