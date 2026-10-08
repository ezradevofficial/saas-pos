<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\Audit\Auditor;
use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\Taxes\ApplyCountryPack;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MD-07: every master data change is visible on the record, newest first,
// with who made it and the values before and after, read from the audit
// log under RLS and only by users who may view the record.
class RecordHistoryTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    public function test_a_partys_history_shows_each_change_with_its_actor(): void
    {
        $id = $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Achieng', 'roles' => ['customer']], $this->headersFor())->json('data.id');
        $this->patchJson("/api/v1/parties/{$id}", ['name' => 'Achieng Odhiambo', 'tags' => ['loyal']], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/parties/{$id}/archive", [], $this->headersFor())->assertOk();

        $response = $this->getJson("/api/v1/history/party/{$id}", $this->headersFor())->assertOk();

        $this->assertSame(['core.party.archive', 'core.party.update', 'core.party.create'], array_column($response->json('data'), 'action'));
        $update = $response->json('data.1');
        $this->assertEquals(['name' => 'Achieng', 'tags' => []], $update['before']);
        $this->assertEquals(['name' => 'Achieng Odhiambo', 'tags' => ['loyal']], $update['after']);
        $this->assertSame(['id' => $this->owner->id, 'name' => 'Owner'], $update['actor']);
        $this->assertNull($response->json('data.2.before'));
        $this->assertSame('Achieng', $response->json('data.2.after.name'));
        $this->assertNotNull($response->json('data.0.occurred_at'));

        // Paginated.
        $this->getJson("/api/v1/history/party/{$id}?per_page=1&page=2", $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.action', 'core.party.update')->assertJsonPath('meta.total', 3);
    }

    public function test_system_changes_have_no_actor_and_other_records_types_are_supported(): void
    {
        $code = $this->inTenant(function () {
            app(ApplyCountryPack::class)->apply($this->acme);
            $code = TaxCode::where('company_id', $this->acme->id)->firstOrFail();
            app(Auditor::class)->record('core.tax_code.note', $code, null, ['note' => 'system']);

            return $code;
        });

        $this->getJson("/api/v1/history/tax_code/{$code->id}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.action', 'core.tax_code.note')
            ->assertJsonPath('data.0.actor', null);

        $this->patchJson("/api/v1/companies/{$this->acme->id}", ['name' => 'Acme Group'], $this->headersFor())->assertOk();
        $this->getJson("/api/v1/history/company/{$this->acme->id}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.action', 'core.company.update')
            ->assertJsonPath('data.0.after.name', 'Acme Group');

        $this->getJson("/api/v1/history/branch/{$this->branchA->id}", $this->headersFor())->assertOk()->assertJsonPath('data.0.action', 'core.branch.create');
        $this->getJson("/api/v1/history/role/{$this->roles->get('cashier')->id}", $this->headersFor())->assertOk();

        // A user's history leaves out security events (sign-ins stay in the audit log).
        $actions = array_column($this->getJson("/api/v1/history/user/{$this->owner->id}", $this->headersFor())->assertOk()->json('data'), 'action');
        $this->assertNotEmpty($actions);
        $this->assertEmpty(array_filter($actions, fn ($a) => str_starts_with($a, 'auth.')));
    }

    public function test_fields_hidden_by_field_rules_are_left_out_of_history_and_the_party(): void
    {
        // RBAC-05: a role that may not see credit limits. Built before any request.
        $clerk = $this->inTenant(function () {
            app(TenantCurrencies::class)->activate('KES');
            $role = $this->role('Party clerk', ['core.party.view', 'core.party.edit']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'party', 'field' => 'credit_limit_minor', 'mode' => FieldRule::HIDDEN]);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });

        $id = $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Baraka', 'roles' => ['customer'], 'credit_limit' => '100', 'credit_limit_currency' => 'KES'], $this->headersFor())->json('data.id');
        $this->patchJson("/api/v1/parties/{$id}", ['credit_limit' => '200', 'credit_limit_currency' => 'KES'], $this->headersFor())->assertOk();
        $this->patchJson("/api/v1/parties/{$id}", ['name' => 'Baraka Mwangi'], $this->headersFor())->assertOk();

        // The owner sees all three entries, with the limit.
        $owner = $this->getJson("/api/v1/history/party/{$id}", $this->headersFor())->assertOk();
        $this->assertSame(['core.party.update', 'core.party.update', 'core.party.create'], array_column($owner->json('data'), 'action'));
        $this->assertSame(['credit_limit_minor' => 10000], $owner->json('data.1.before'));

        // The clerk: the limit-only change is gone, and no entry shows the limit.
        $clerkHeaders = $this->headersFor($clerk);
        $history = $this->getJson("/api/v1/history/party/{$id}", $clerkHeaders)->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertSame(['core.party.update', 'core.party.create'], array_column($history->json('data'), 'action'));
        $this->assertArrayNotHasKey('credit_limit_minor', $history->json('data.1.after'));
        $this->assertSame('KES', $history->json('data.1.after.credit_limit_currency'));
        $this->assertStringNotContainsString('credit_limit_minor', $history->getContent());

        // Detail and list agree with the history.
        $this->assertArrayNotHasKey('credit_limit', $this->getJson("/api/v1/parties/{$id}", $clerkHeaders)->assertOk()->json('data'));
        $this->assertArrayNotHasKey('credit_limit', $this->getJson('/api/v1/parties', $clerkHeaders)->assertOk()->json('data.0'));
        $this->assertArrayNotHasKey('credit_limit', $this->patchJson("/api/v1/parties/{$id}", ['name' => 'B. Mwangi'], $clerkHeaders)->assertOk()->json('data'));
        $this->assertSame(['amount_minor' => '20000', 'currency' => 'KES'], $this->getJson("/api/v1/parties/{$id}", $this->headersFor())->json('data.credit_limit'));
    }

    public function test_history_needs_view_on_the_record_and_unknown_types_are_not_found(): void
    {
        $globex = $this->inTenant(fn () => $this->company('Globex'));
        $this->putJson('/api/v1/master-data/settings', ['data_type' => 'suppliers', 'mode' => 'per_company'], $this->headersFor())->assertOk();
        $globexSupplier = $this->postJson('/api/v1/parties', ['kind' => 'organisation', 'name' => 'G', 'roles' => ['supplier'], 'company_id' => $globex->id], $this->headersFor())->json('data.id');
        $shared = $this->postJson('/api/v1/parties', ['kind' => 'organisation', 'name' => 'S', 'roles' => ['customer']], $this->headersFor())->json('data.id');

        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $this->getJson("/api/v1/history/party/{$shared}", $cashier)->assertOk();
        $this->getJson("/api/v1/history/party/{$globexSupplier}", $cashier)->assertNotFound();
        $this->getJson("/api/v1/history/company/{$this->acme->id}", $cashier)->assertNotFound();

        $this->getJson("/api/v1/history/spaceship/{$shared}", $this->headersFor())->assertNotFound();
        $this->getJson('/api/v1/history/party/not-a-uuid', $this->headersFor())->assertNotFound();
        $this->getJson("/api/v1/history/company/{$shared}", $this->headersFor())->assertNotFound();

        // Another tenant's record does not exist here (RLS).
        $other = $this->otherTenant();
        $this->getJson("/api/v1/history/company/{$other['company']->id}", $this->headersFor())->assertNotFound();
        $this->getJson("/api/v1/history/party/{$shared}", $this->headersFor($other['user']))->assertNotFound();
        $this->assertInstanceOf(Company::class, $globex);
    }
}
