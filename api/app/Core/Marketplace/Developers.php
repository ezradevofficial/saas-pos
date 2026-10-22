<?php

namespace App\Core\Marketplace;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Marketplace\Models\MarketplaceDeveloper;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * MKT-01: a tenant registers as a developer (a sandbox from the developer
 * portal, API-05, or any live tenant) with a public name, a contact email
 * and the marketplace terms (the 80/20 revenue share, MKT-03). One
 * developer account per tenant; audited (`core.developer.*`).
 */
class Developers
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    public function current(): ?MarketplaceDeveloper
    {
        $tenantId = $this->tenants->id();

        return $tenantId === null ? null : MarketplaceDeveloper::query()->where('developer_tenant_id', $tenantId)->first();
    }

    /** @param  array{name: string, email: string, website?: ?string}  $data */
    public function register(User $actor, array $data): MarketplaceDeveloper
    {
        $developer = $this->current();

        if ($developer !== null) {
            $before = $developer->only(['name', 'email', 'website']);
            $developer->fill(array_intersect_key($data, array_flip(['name', 'email', 'website'])));

            if ($developer->isDirty()) {
                $developer->save();
                $this->auditor->record('core.developer.update', $developer, $before, $developer->only(['name', 'email', 'website']));
            }

            return $developer;
        }

        $developer = MarketplaceDeveloper::create([
            'developer_tenant_id' => $this->tenants->require(),
            'name' => $data['name'],
            'email' => $data['email'],
            'website' => $data['website'] ?? null,
            'terms_accepted_at' => CarbonImmutable::now(),
            'created_by' => $actor->id,
        ]);

        $this->auditor->record('core.developer.register', $developer, null, [
            ...$developer->only(['name', 'email', 'website']),
            'share_percent' => (int) config('marketplace.developer_share_percent', 80),
        ]);

        return $developer;
    }
}
