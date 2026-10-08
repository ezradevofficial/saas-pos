<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MD-06: likely duplicates (same phone, same tax ID, similar name) are
// returned as `meta.possible_duplicates` on create and update. They never
// block, and only records the user can see are named.
class DuplicateWarningsTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    private function create(array $body, ?array $headers = null)
    {
        return $this->postJson('/api/v1/parties', ['kind' => 'organisation', 'roles' => ['customer'], ...$body], $headers ?? $this->headersFor())->assertCreated();
    }

    public function test_same_phone_tax_id_or_similar_name_is_reported_without_blocking(): void
    {
        $byPhone = $this->create(['name' => 'Wanjiku Stores', 'phones' => [['number' => '0712 345 678']]])->json('data.id');
        $byTaxId = $this->create(['name' => 'Kamau Holdings', 'tax_id' => 'P051234567A'])->json('data.id');
        $byName = $this->create(['name' => 'Nyota Supermarket'])->json('data.id');
        $this->create(['name' => 'Unrelated Bakery']);

        // A phone typed differently, the same tax ID typed with spaces, a near name.
        $wStores = $this->create(['name' => 'W. Stores', 'phones' => [['number' => '+254 712 345 678']]])
            ->assertJsonPath('meta.possible_duplicates', [['id' => $byPhone, 'name' => 'Wanjiku Stores', 'reason' => 'phone']])->json('data.id');
        $khLtd = $this->create(['name' => 'KH Ltd', 'tax_id' => 'p051 234 567a'])
            ->assertJsonPath('meta.possible_duplicates', [['id' => $byTaxId, 'name' => 'Kamau Holdings', 'reason' => 'tax_id']])->json('data.id');
        $nearName = $this->create(['name' => 'Nyota Supermarkets'])
            ->assertJsonPath('meta.possible_duplicates', [['id' => $byName, 'name' => 'Nyota Supermarket', 'reason' => 'name']])->json('data.id');

        // Nothing alike: no warning. Below the 0.6 similarity floor: none either.
        $this->create(['name' => 'Totally Different Garage'])->assertJsonPath('meta.possible_duplicates', []);
        $this->create(['name' => 'Nyota'])->assertJsonPath('meta.possible_duplicates', []);

        // Update warns too (tax ID matches before name matches), and never names the record itself.
        $duplicates = $this->patchJson("/api/v1/parties/{$nearName}", ['tax_id' => 'P051234567A'], $this->headersFor())->assertOk()
            ->json('meta.possible_duplicates');
        $this->assertEqualsCanonicalizing([$byTaxId, $khLtd], array_column(array_slice($duplicates, 0, 2), 'id'));
        $this->assertSame(['tax_id', 'tax_id', 'name'], array_column($duplicates, 'reason'));
        $this->assertSame($byName, $duplicates[2]['id']);

        // Archived records are not reported.
        $this->postJson("/api/v1/parties/{$byPhone}/archive", [], $this->headersFor())->assertOk();
        $this->create(['name' => 'Another', 'phones' => [['number' => '0712345678']]])
            ->assertJsonPath('meta.possible_duplicates', [['id' => $wStores, 'name' => 'W. Stores', 'reason' => 'phone']]);
    }

    public function test_strongest_reason_first_at_most_five(): void
    {
        foreach (range(1, 6) as $i) {
            $this->create(['name' => "Baraka Traders {$i}"]);
        }
        $sameTax = $this->create(['name' => 'Someone Else', 'tax_id' => 'A1'])->json('data.id');

        $duplicates = $this->create(['name' => 'Baraka Traders', 'tax_id' => 'A1'])->json('meta.possible_duplicates');

        $this->assertCount(5, $duplicates);
        $this->assertSame(['id' => $sameTax, 'name' => 'Someone Else', 'reason' => 'tax_id'], $duplicates[0]);
        $this->assertSame(['name'], array_values(array_unique(array_column(array_slice($duplicates, 1), 'reason'))));
    }

    public function test_only_records_the_user_can_see_are_named(): void
    {
        $globex = $this->inTenant(fn () => $this->company('Globex'));
        $this->putJson('/api/v1/master-data/settings', ['data_type' => 'suppliers', 'mode' => 'per_company'], $this->headersFor())->assertOk();
        $hidden = $this->create(['name' => 'Secret Supplier Co', 'roles' => ['supplier'], 'company_id' => $globex->id, 'tax_id' => 'S1'])->json('data.id');

        $acmeCashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $this->create(['name' => 'Secret Supplier Co', 'tax_id' => 'S1'], $acmeCashier)->assertJsonPath('meta.possible_duplicates', []);

        // The owner sees it.
        $this->create(['name' => 'Secret Supplier Co.', 'tax_id' => 'S1'])
            ->assertJsonPath('meta.possible_duplicates.0', ['id' => $hidden, 'name' => 'Secret Supplier Co', 'reason' => 'tax_id']);

        $this->inTenant(fn () => $this->assertSame(3, Party::count()));
        $this->assertInstanceOf(Company::class, $globex);
    }
}
