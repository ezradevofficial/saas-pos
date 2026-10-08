<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MD-01: parties with roles, normalised contacts, filters and search;
// archived, never deleted (TEN-06); every change audited (MD-07).
class PartyApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->inTenant(function () {
            app(TenantCurrencies::class)->activate('KES');
            app(TenantCurrencies::class)->activate('USD');
        });
    }

    private function create(array $body, ?array $headers = null)
    {
        return $this->postJson('/api/v1/parties', $body, $headers ?? $this->headersFor());
    }

    private function customer(string $name, array $extra = []): string
    {
        return $this->create(['kind' => 'organisation', 'name' => $name, 'roles' => ['customer'], ...$extra])->assertCreated()->json('data.id');
    }

    public function test_a_party_is_created_with_normalised_contacts(): void
    {
        $response = $this->create([
            'kind' => 'organisation',
            'name' => 'Duka Moja Ltd',
            'legal_name' => 'Duka Moja Limited',
            'tax_id' => ' p051 234 567a ',
            'roles' => ['supplier', 'customer'],
            'tags' => ['VIP', 'wholesale', 'vip'],
            'phones' => [['number' => '0712 345 678', 'label' => 'Mobile'], ['number' => '+254712345678']],
            'emails' => [['address' => ' Orders@DukaMoja.CO.KE ']],
            'addresses' => [['line1' => 'Moi Avenue 12', 'city' => 'Nairobi', 'country' => 'KE']],
            'currency' => 'KES',
            'payment_terms_days' => 30,
            'credit_limit' => '150000.50',
            'credit_limit_currency' => 'KES',
        ])->assertCreated();

        $response->assertJsonPath('data.shared', true)
            ->assertJsonPath('data.company_id', null)
            ->assertJsonPath('data.tax_id', 'P051234567A')
            ->assertJsonPath('data.roles', ['customer', 'supplier'])
            ->assertJsonPath('data.tags', ['vip', 'wholesale'])
            ->assertJsonPath('data.phones', [['number' => '+254712345678', 'label' => 'Mobile']])
            ->assertJsonPath('data.emails', [['address' => 'orders@dukamoja.co.ke', 'label' => null]])
            ->assertJsonPath('data.addresses.0.line1', 'Moi Avenue 12')
            ->assertJsonPath('data.addresses.0.postal_code', null)
            ->assertJsonPath('data.credit_limit', ['amount_minor' => '15000050', 'currency' => 'KES'])
            ->assertJsonPath('data.payment_terms_days', 30)
            ->assertJsonPath('meta.possible_duplicates', []);

        $this->inTenant(function () use ($response) {
            $party = Party::findOrFail($response->json('data.id'));
            $this->assertSame(['customer', 'supplier'], $party->roles);
            $entry = AuditEntry::where('action', 'core.party.create')->sole();
            $this->assertSame($party->id, $entry->auditable_id);
            $this->assertSame(['vip', 'wholesale'], $entry->after['tags']);
        });
    }

    public function test_validation_refuses_bad_input(): void
    {
        $this->create(['kind' => 'robot', 'name' => 'X', 'roles' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['kind', 'roles']);
        $this->create(['kind' => 'person', 'name' => 'X', 'roles' => ['landlord']])
            ->assertUnprocessable()->assertJsonValidationErrors('roles.0');
        $this->create(['kind' => 'person', 'name' => 'X', 'roles' => ['customer'], 'phones' => [['number' => '12345']]])
            ->assertUnprocessable()->assertJsonValidationErrors('phones.0.number');
        $this->create(['kind' => 'person', 'name' => 'X', 'roles' => ['customer'], 'emails' => [['address' => 'not-an-email']]])
            ->assertUnprocessable()->assertJsonValidationErrors('emails.0.address');
        $this->create(['kind' => 'person', 'name' => 'X', 'roles' => ['customer'], 'credit_limit' => '10.505', 'credit_limit_currency' => 'KES'])
            ->assertUnprocessable()->assertJsonValidationErrors('credit_limit');
        $this->create(['kind' => 'person', 'name' => 'X', 'roles' => ['customer'], 'credit_limit' => 10.5, 'credit_limit_currency' => 'KES'])
            ->assertUnprocessable()->assertJsonValidationErrors('credit_limit');
        $this->create(['kind' => 'person', 'name' => 'X', 'roles' => ['customer'], 'credit_limit' => '100'])
            ->assertUnprocessable()->assertJsonValidationErrors('credit_limit_currency');
        $this->create(['kind' => 'person', 'name' => 'X', 'roles' => ['customer'], 'currency' => 'EUR'])
            ->assertUnprocessable()->assertJsonValidationErrors('currency');
        $this->create(['kind' => 'person', 'name' => 'X', 'roles' => ['customer'], 'tags' => ['ok', 'no;semicolons']])
            ->assertUnprocessable()->assertJsonValidationErrors('tags.1');

        // Shared customers belong to no company (TEN-08).
        $this->create(['kind' => 'person', 'name' => 'X', 'roles' => ['customer'], 'company_id' => $this->acme->id])
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');

        $this->inTenant(fn () => $this->assertSame(0, Party::count()));
    }

    public function test_a_price_list_must_be_active_and_of_the_partys_company(): void
    {
        [$acmeList, $otherList, $archived] = $this->inTenant(function () {
            $globex = $this->company('Globex');

            return [
                PriceList::create(['company_id' => $this->acme->id, 'name' => 'Retail', 'currency' => 'KES'])->id,
                PriceList::create(['company_id' => $globex->id, 'name' => 'Globex retail', 'currency' => 'KES'])->id,
                tap(PriceList::create(['company_id' => $this->acme->id, 'name' => 'Old', 'currency' => 'KES']))->archive()->id,
            ];
        });

        // Shared customer: any active list of a company the user reaches.
        $this->customer('Shared with list', ['price_list_id' => $otherList]);
        $this->create(['kind' => 'person', 'name' => 'X', 'roles' => ['customer'], 'price_list_id' => $archived])
            ->assertUnprocessable()->assertJsonValidationErrors('price_list_id');

        // A company's supplier: only that company's lists.
        $this->putJson('/api/v1/master-data/settings', ['data_type' => 'suppliers', 'mode' => 'per_company'], $this->headersFor())->assertOk();
        $this->create(['kind' => 'organisation', 'name' => 'Y', 'roles' => ['supplier'], 'company_id' => $this->acme->id, 'price_list_id' => $otherList])
            ->assertUnprocessable()->assertJsonValidationErrors('price_list_id');
        $this->create(['kind' => 'organisation', 'name' => 'Y', 'roles' => ['supplier'], 'company_id' => $this->acme->id, 'price_list_id' => $acmeList])
            ->assertCreated()->assertJsonPath('data.price_list_id', $acmeList);
    }

    public function test_update_replaces_lists_and_is_audited_with_before_and_after(): void
    {
        $id = $this->customer('Mama Mboga', ['phones' => [['number' => '0712000001']], 'tags' => ['market']]);

        $this->patchJson("/api/v1/parties/{$id}", [
            'name' => 'Mama Mboga Greens',
            'phones' => [['number' => '+243812345678', 'label' => 'Kinshasa']],
            'tags' => [],
            'credit_limit' => '500',
            'credit_limit_currency' => 'USD',
        ], $this->headersFor())->assertOk()
            ->assertJsonPath('data.name', 'Mama Mboga Greens')
            ->assertJsonPath('data.phones', [['number' => '+243812345678', 'label' => 'Kinshasa']])
            ->assertJsonPath('data.tags', [])
            ->assertJsonPath('data.credit_limit', ['amount_minor' => '50000', 'currency' => 'USD'])
            ->assertJsonStructure(['meta' => ['possible_duplicates']]);

        $this->patchJson("/api/v1/parties/{$id}", ['credit_limit' => null], $this->headersFor())
            ->assertOk()->assertJsonPath('data.credit_limit', null);

        $this->inTenant(function () use ($id) {
            $update = AuditEntry::where('action', 'core.party.update')->where('auditable_id', $id)->orderBy('seq')->first();
            $this->assertSame('Mama Mboga', $update->before['name']);
            $this->assertSame('Mama Mboga Greens', $update->after['name']);
            $this->assertSame(['market'], $update->before['tags']);
            $this->assertSame([], $update->after['tags']);
        });
    }

    public function test_archive_and_restore_with_status_filter(): void
    {
        $id = $this->customer('Old customer');
        $this->customer('Current customer');

        $this->postJson("/api/v1/parties/{$id}/archive", [], $this->headersFor())->assertOk()->assertJsonPath('data.archived_at', fn ($v) => $v !== null);
        $this->postJson("/api/v1/parties/{$id}/archive", [], $this->headersFor())->assertOk();

        $this->getJson('/api/v1/parties', $this->headersFor())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Current customer');
        $this->getJson('/api/v1/parties?status=archived', $this->headersFor())->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/parties?status=all', $this->headersFor())->assertOk()->assertJsonCount(2, 'data');

        $this->postJson("/api/v1/parties/{$id}/restore", [], $this->headersFor())->assertOk()->assertJsonPath('data.archived_at', null);
        $this->inTenant(function () use ($id) {
            $this->assertSame(['core.party.create', 'core.party.archive', 'core.party.restore'],
                AuditEntry::where('auditable_id', $id)->orderBy('seq')->pluck('action')->all());
        });
    }

    public function test_role_tag_and_search_filters(): void
    {
        $acme = $this->customer('Acme Hardware', ['tags' => ['vip'], 'tax_id' => 'P000111222Z']);
        $this->customer('Kilimani Grocers', ['phones' => [['number' => '0722 555 444']]]);
        $supplier = $this->create(['kind' => 'organisation', 'name' => 'Acme Supplies', 'roles' => ['supplier', 'contact'], 'tags' => ['vip']])->json('data.id');

        $ids = fn (string $query) => array_column($this->getJson("/api/v1/parties?{$query}", $this->headersFor())->assertOk()->json('data'), 'id');

        $this->assertEqualsCanonicalizing([$supplier], $ids('role=supplier'));
        $this->assertCount(2, $ids('role=customer'));
        $this->assertEqualsCanonicalizing([$acme, $supplier], $ids('tag=VIP'));
        $this->assertSame([$acme], $ids('tag=vip&role=customer'));

        // Name (contains, case-insensitive), similar spelling, tax ID, phone digits.
        $this->assertEqualsCanonicalizing([$acme, $supplier], $ids('search=acme'));
        $this->assertContains($acme, $ids('search=Acme+Hardwares'));
        $this->assertSame([$acme], $ids('search=p000111222z'));
        $this->assertCount(1, $ids('search=555444'));
        $this->assertSame([], $ids('search=100%25'));

        $this->getJson('/api/v1/parties?role=landlord', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_tags_with_spaces_and_hyphens_round_trip(): void
    {
        $id = $this->customer('Tagged', ['tags' => ['Top buyer', 'b2b-north', 'x_y']]);

        $this->getJson("/api/v1/parties/{$id}", $this->headersFor())->assertOk()->assertJsonPath('data.tags', ['top buyer', 'b2b-north', 'x_y']);
        $this->getJson('/api/v1/parties?tag='.rawurlencode('Top buyer'), $this->headersFor())->assertOk()->assertJsonPath('data.0.id', $id);
        $this->inTenant(fn () => $this->assertSame(['top buyer', 'b2b-north', 'x_y'], Party::findOrFail($id)->tags));
    }

    public function test_permissions_404_and_403(): void
    {
        $id = $this->customer('Visible customer');
        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $storekeeper = $this->headersFor($this->userWith('storekeeper', Scope::location($this->locationA->id)));

        // A cashier sees and creates shared customers but does not edit or archive them.
        $this->getJson("/api/v1/parties/{$id}", $cashier)->assertOk();
        $this->customer('At the till', []);
        $this->create(['kind' => 'person', 'name' => 'Walk-in', 'roles' => ['customer']], $cashier)->assertCreated();
        $this->patchJson("/api/v1/parties/{$id}", ['name' => 'Renamed'], $cashier)->assertForbidden();
        $this->postJson("/api/v1/parties/{$id}/archive", [], $cashier)->assertForbidden();

        // No party permission at all: not found, and no list.
        $this->getJson("/api/v1/parties/{$id}", $storekeeper)->assertNotFound();
        $this->getJson('/api/v1/parties', $storekeeper)->assertForbidden();
        $this->create(['kind' => 'person', 'name' => 'Y', 'roles' => ['customer']], $storekeeper)->assertForbidden();

        // Malformed and unknown ids.
        $this->getJson('/api/v1/parties/not-a-uuid', $this->headersFor())->assertNotFound();
        $this->getJson('/api/v1/parties/01890000-0000-7000-8000-000000000000', $this->headersFor())->assertNotFound();
    }

    public function test_parties_of_another_tenant_are_invisible_even_to_search(): void
    {
        $this->customer('Shared Name Traders', ['tax_id' => 'P999', 'phones' => [['number' => '0733000111']]]);
        $other = $this->otherTenant();
        $otherHeaders = $this->headersFor($other['user']);

        $this->getJson('/api/v1/parties?search=Shared+Name', $otherHeaders)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/parties?search=P999', $otherHeaders)->assertOk()->assertJsonCount(0, 'data');

        // Their own look-alike gets no duplicate warning pointing at this tenant.
        $this->postJson('/api/v1/parties', ['kind' => 'organisation', 'name' => 'Shared Name Traders', 'roles' => ['customer'], 'tax_id' => 'P999', 'phones' => [['number' => '0733000111']]], $otherHeaders)
            ->assertCreated()->assertJsonPath('meta.possible_duplicates', []);

        // RLS: in this tenant's context the other tenant's party does not exist.
        $this->inTenant(fn () => $this->assertSame(1, Party::count()));
        app(TenantContext::class)->set(null);
        $this->assertSame(0, DB::table('parties')->count(), 'parties are visible without a tenant context');
        $this->assertSame(MasterDataSharing::SHARED, $this->inTenant(fn () => app(MasterDataSharing::class)->mode('customers')));
    }
}
