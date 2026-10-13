<?php

namespace Tests\Feature\Core\Imports;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\TenantCurrencies;
use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\MasterData\Items\DefaultUoms;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsImports;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// IMP-01..IMP-03: customers and suppliers through the import wizard, with the
// party API's own rules (MD-01), custom fields (CF-03), field rules
// (RBAC-05), audit (AUD-01), undo (IMP-02) and tenant isolation (TEN-01).
class PartyImportTest extends TestCase
{
    use BuildsImports, BuildsOrganisation, RefreshTenantDatabase;

    private const HEADERS = ['Name', 'Tax ID', 'Phones', 'Emails', 'Address line 1', 'City', 'Country', 'Currency', 'Payment terms (days)', 'Tags'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        $this->setUpOrganisation();
        $this->inTenant(fn () => app(TenantCurrencies::class)->provisionFor($this->acme));
    }

    private function row(int $i, array $override = []): array
    {
        return array_values(array_replace([
            "Customer {$i}", "P05100000{$i}A", '0712 345 67'.$i, "buyer{$i}@example.com", 'Moi Avenue {$i}', 'Nairobi', 'KE', 'KES', '30', 'wholesale; vip',
        ], $override));
    }

    public function test_customers_are_imported_through_the_party_rules(): void
    {
        $import = $this->importFile('customers', $this->csvFile([self::HEADERS, $this->row(1), $this->row(2)]));

        $this->assertSame([2, 0], [$import['created_rows'], $import['failed_rows']]);
        $this->inTenant(function () use ($import) {
            $party = Party::query()->where('tax_id', 'P051000001A')->sole();
            $this->assertSame(['customer'], $party->roles);
            $this->assertSame(['+254712345671'], $party->phoneNumbers());
            $this->assertSame(['wholesale', 'vip'], $party->tags);
            $this->assertSame('Nairobi', $party->addresses[0]['city']);
            $this->assertSame(30, $party->payment_terms_days);
            $this->assertSame($import['id'], AuditEntry::query()->where('auditable_id', $party->id)->sole()->after['metadata']['import_id']);
        });
    }

    public function test_row_errors_name_their_columns(): void
    {
        $file = $this->csvFile([self::HEADERS, $this->row(1, [2 => 'not a phone']), $this->row(2, [3 => 'nope']), $this->row(3, [8 => 'ten']), $this->row(4)]);
        $id = $this->upload('customers', $file)->assertCreated()->json('data.id');

        $rows = collect($this->getJson("/api/v1/imports/{$id}/preview", $this->headersFor())->assertOk()->json('data.rows'));
        $this->assertSame(['phones', 'emails', 'payment_terms_days'], $rows->take(3)->map(fn ($row) => $row['errors'][0]['column'])->all());
        $this->assertSame('valid', $rows[3]['status']);
    }

    public function test_suppliers_with_french_headers_and_update_mode_matched_by_tax_id(): void
    {
        // An existing customer becomes a supplier too.
        $this->importFile('customers', $this->csvFile([self::HEADERS, $this->row(1)]));

        $french = ['Nom', 'Numéro fiscal', 'Délai de paiement (jours)', 'Étiquettes'];
        $file = $this->xlsxFile([$french, ['Fournisseur Un', 'P051000001A', 45, 'boissons'], ['Fournisseur Deux', 'P059999999Z', 15, '']]);
        $id = $this->upload('suppliers', $file)->assertCreated()
            ->assertJsonPath('data.mapping.name', 0)->assertJsonPath('data.mapping.tax_id', 1)->assertJsonPath('data.mapping.payment_terms_days', 2)
            ->json('data.id');

        $this->patchJson("/api/v1/imports/{$id}", ['mode' => 'upsert'], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/imports/{$id}/run", [], $this->headersFor())->assertStatus(202);
        $this->getJson("/api/v1/imports/{$id}", $this->headersFor())->assertJsonPath('data.created_rows', 1)->assertJsonPath('data.updated_rows', 1);

        $this->inTenant(function () {
            $party = Party::query()->where('tax_id', 'P051000001A')->sole();
            $this->assertSame(['customer', 'supplier'], $party->roles);
            $this->assertSame('Fournisseur Un', $party->name);
            $this->assertSame(45, $party->payment_terms_days);
            $this->assertSame(['supplier'], Party::query()->where('tax_id', 'P059999999Z')->sole()->roles);
        });
    }

    public function test_custom_fields_and_field_rules(): void
    {
        $this->inTenant(fn () => CustomFieldDefinition::create(['entity' => 'party', 'key' => 'route', 'type' => 'text', 'label' => 'Route', 'max_value' => '5']));
        $import = $this->importFile('customers', $this->csvFile([['Name', 'Route'], ['One', 'R1'], ['Two', 'TOO-LONG']]));
        $this->assertSame([1, 1], [$import['created_rows'], $import['failed_rows']]);
        $this->inTenant(fn () => $this->assertSame(['route' => 'R1'], Party::query()->where('name', 'One')->sole()->custom));

        // RBAC-05: a read-only tax ID refuses every row naming it.
        $clerk = $this->inTenant(function () {
            $role = $this->role('Importer, tax IDs read-only', ['core.import.run', 'core.party.view', 'core.party.create']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'party', 'field' => 'tax_id', 'mode' => 'readonly']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });
        $import = $this->importFile('customers', $this->csvFile([self::HEADERS, $this->row(5)]), user: $clerk);
        $this->assertSame([0, 1], [$import['created_rows'], $import['failed_rows']]);
    }

    public function test_undo_restores_updated_parties_and_archives_created_ones(): void
    {
        $this->importFile('customers', $this->csvFile([self::HEADERS, $this->row(1)]));
        $import = $this->importFile('customers', $this->csvFile([['Name', 'Tax ID'], ['Renamed', 'P051000001A'], ['Brand new', 'P051000009A']]), 'upsert');

        $this->postJson("/api/v1/imports/{$import['id']}/undo", [], $this->headersFor())->assertStatus(202);

        $this->inTenant(function () {
            $this->assertSame('Customer 1', Party::query()->where('tax_id', 'P051000001A')->sole()->name);
            $this->assertNotNull(Party::query()->where('tax_id', 'P051000009A')->sole()->archived_at);
        });
    }

    public function test_undo_is_refused_when_a_document_names_an_imported_customer(): void
    {
        $import = $this->importFile('customers', $this->csvFile([self::HEADERS, $this->row(1), $this->row(2)]));

        // An item's lookup field names the second customer (CF-01).
        $this->inTenant(function () {
            app(DefaultUoms::class)->seed();
            CustomFieldDefinition::create(['entity' => 'item', 'key' => 'buyer', 'type' => 'lookup', 'label' => 'Buyer', 'lookup_target' => 'party']);
            $item = Item::create(['code' => 'X', 'name' => 'X', 'type' => 'stock', 'base_uom_id' => Uom::query()->value('id')]);
            $item->custom = ['buyer' => Party::query()->where('tax_id', 'P051000002A')->value('id')];
            $item->save();
        });

        $this->postJson("/api/v1/imports/{$import['id']}/undo", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('blocking.count', 1)
            ->assertJsonPath('blocking.rows.0.row', 3)
            ->assertJsonPath('blocking.rows.0.reason', __('imports.usage.document'));
        $this->inTenant(fn () => $this->assertSame(0, Party::query()->whereNotNull('archived_at')->count()));
    }

    public function test_permission_denied_and_other_tenants(): void
    {
        // A storekeeper imports nothing (no core.import.run); an accountant imports customers but not items.
        $storekeeper = $this->userWith('storekeeper', Scope::tenant());
        $this->upload('customers', $this->csvFile([self::HEADERS, $this->row(1)]), $storekeeper)->assertForbidden();

        $accountant = $this->userWith('accountant', Scope::tenant());
        $this->upload('items', $this->csvFile([['Code'], ['X']]), $accountant)->assertForbidden();
        $this->assertSame(['customers', 'suppliers'], array_column($this->getJson('/api/v1/imports/importers', $this->headersFor($accountant))->json('data'), 'key'));
        $import = $this->importFile('customers', $this->csvFile([self::HEADERS, $this->row(1)]), user: $accountant);
        $this->assertSame(1, $import['created_rows']);

        $other = $this->otherTenant()['user'];
        $this->getJson("/api/v1/imports/{$import['id']}", $this->headersFor($other))->assertNotFound();
        $this->getJson("/api/v1/imports/{$import['id']}/preview", $this->headersFor($other))->assertNotFound();
        $this->inTenant(fn () => $this->assertSame(1, Party::query()->count()));
    }
}
