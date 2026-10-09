<?php

namespace App\Core\Branding;

use App\Core\Audit\Auditor;
use App\Core\Branding\Domains\TenantDomains;
use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;

/**
 * BR-04, BR-06, BR-07: the tenant's branding settings.
 *
 * - `slug`: the subdomain, `{slug}.{branding.base_domain}` (tenants.slug,
 *   unique across tenants);
 * - `email_from_name`, `email_from_address`: the sender of the tenant's
 *   notification emails, used only while the address is on one of the
 *   tenant's verified domains (sender());
 * - `sms_sender_id`: the alphanumeric sender ID, where the SMS provider
 *   allows one (stored for the provider set-up);
 * - `hide_platform` (BR-07): hides "Powered by"; set only by the platform
 *   (`tenant:branding`), never through the tenant API.
 *
 * Kept in tenants.settings.branding (slug in its own column). Changes are
 * audited as `core.branding.settings_update` (AUD-01).
 */
class BrandingSettings
{
    public const FIELDS = ['email_from_name', 'email_from_address', 'sms_sender_id'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
        private readonly TenantDomains $domains,
    ) {}

    /** @return array<string, mixed> */
    public function values(?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::query()->findOrFail($this->tenants->require());
        $branding = (array) (($tenant->settings ?? [])['branding'] ?? []);
        $base = config('branding.base_domain');

        return [
            'slug' => $tenant->slug,
            'host' => $tenant->slug !== null && $base ? "{$tenant->slug}.{$base}" : null,
            'base_domain' => $base ?: null,
            'email_from_name' => $branding['email_from_name'] ?? null,
            'email_from_address' => $branding['email_from_address'] ?? null,
            'sms_sender_id' => $branding['sms_sender_id'] ?? null,
            'hide_platform' => (bool) ($branding['hide_platform'] ?? false),
        ];
    }

    /** @param array<string, mixed> $input slug and FIELDS, each optional */
    public function update(array $input): array
    {
        try {
            return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($input) {
                $tenant = Tenant::query()->whereKey($this->tenants->require())->lockForUpdate()->firstOrFail();
                $before = $this->values($tenant);
                $settings = $tenant->settings ?? [];
                $branding = (array) ($settings['branding'] ?? []);

                foreach (self::FIELDS as $field) {
                    if (array_key_exists($field, $input)) {
                        $value = is_string($input[$field]) ? trim($input[$field]) : null;
                        $branding[$field] = $value === '' ? null : ($field === 'email_from_address' ? Str::lower((string) $value) : $value);
                    }
                }

                $settings['branding'] = $branding;
                $tenant->settings = $settings;

                if (array_key_exists('slug', $input)) {
                    $tenant->slug = is_string($input['slug']) && $input['slug'] !== '' ? Str::lower($input['slug']) : null;
                }

                $tenant->save();
                $after = $this->values($tenant);
                $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

                if ($changed !== []) {
                    $this->auditor->record('core.branding.settings_update', $tenant,
                        array_intersect_key($before, array_flip($changed)),
                        array_intersect_key($after, array_flip($changed)),
                    );
                }

                return $after;
            });
        } catch (UniqueConstraintViolationException) {
            $message = __('branding.errors.slug_taken');

            throw new ApiException(422, 'slug_taken', $message, ['slug' => [$message]]);
        }
    }

    /**
     * BR-06: the sender of the current tenant's emails, while its address
     * is on a verified domain of the tenant; else null (the platform's).
     */
    public function sender(): ?Address
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null) {
            return null;
        }

        $values = $this->values(Tenant::query()->find($tenantId) ?? new Tenant);
        $address = $values['email_from_address'];

        if (! is_string($address) || ! str_contains($address, '@') || $this->domains->verified(Str::after($address, '@')) === null) {
            return null;
        }

        return new Address($address, (string) ($values['email_from_name'] ?? ''));
    }
}
