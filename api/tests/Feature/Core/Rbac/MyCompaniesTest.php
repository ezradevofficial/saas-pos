<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-04, RBAC-09 (stage 7B walkthrough): the company switcher lists the
// companies a user holds any role in, even without core.company.view (a
// branch Accountant or Branch Manager), and nothing of another tenant.
class MyCompaniesTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    public function test_a_user_sees_the_companies_they_hold_a_role_in(): void
    {
        $this->setUpOrganisation();
        $other = $this->inTenant(fn () => $this->company('Zawadi Foods'));
        $ids = fn ($user) => array_column($this->getJson('/api/v1/me/companies', $this->headersFor($user))->assertOk()->json('data'), 'id');

        // A branch Accountant and a Branch Manager: their branch's company only.
        $this->assertSame([$this->acme->id], $ids($this->userWith('accountant', Scope::branch($this->branchA->id))));
        $this->assertSame([$this->acme->id], $ids($this->userWith('branch_manager', Scope::branch($this->branchB->id))));
        // A storekeeper at a location: that location's company.
        $this->assertSame([$this->acme->id], $ids($this->userWith('storekeeper', Scope::location($this->locationA->id))));
        // The Owner (tenant-wide): every company of the tenant, nothing of another tenant.
        $owner = $ids($this->owner);
        sort($owner);
        $expected = [$this->acme->id, $other->id];
        sort($expected);
        $this->assertSame($expected, $owner);
        $foreign = $this->otherTenant();
        $this->assertSame([$foreign['company']->id], $ids($foreign['user']));

        // Only what the switcher needs.
        $first = $this->getJson('/api/v1/me/companies', $this->headersFor())->json('data.0');
        $this->assertSame(['id', 'name', 'country', 'base_currency', 'timezone', 'archived_at'], array_keys($first));
    }
}
