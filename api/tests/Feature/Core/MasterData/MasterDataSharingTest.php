<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\Audit\AuditEntry;
use App\Core\Http\ApiException;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Sharing\MasterDataSetting;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\MasterData\Sharing\SharingSwitchGuard;
use App\Core\MasterData\Taxes\ApplyCountryPack;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCategoryCode;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// TEN-08 (review focus 3): shared or per-company master data. Shared
// records are seen from any scope; a company's records only by users whose
// scope touches that company. Switching modes never orphans or exposes a
// record.
class MasterDataSharingTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private Company $globex;

    private Branch $globexBranch;

    private Location $globexLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->inTenant(function () {
            $this->globex = $this->company('Globex');
            $this->globexBranch = $this->branch($this->globex, 'G');
            $this->globexLocation = $this->location($this->globexBranch, 'Globex outlet');
        });
    }

    private function settings(array $body, ?array $headers = null)
    {
        return $this->putJson('/api/v1/master-data/settings', $body, $headers ?? $this->headersFor());
    }

    private function party(array $body, ?array $headers = null)
    {
        return $this->postJson('/api/v1/parties', ['kind' => 'organisation', ...$body], $headers ?? $this->headersFor());
    }

    /** @return array<string, string> mode by data type */
    private function modes(): array
    {
        return collect($this->getJson('/api/v1/master-data/settings', $this->headersFor())->assertOk()->json('data'))
            ->pluck('mode', 'data_type')->all();
    }

    public function test_every_type_is_shared_until_switched_and_only_tenant_admins_switch(): void
    {
        $this->assertSame(['items' => 'shared', 'customers' => 'shared', 'suppliers' => 'shared', 'employees' => 'shared'], $this->modes());

        $manager = $this->headersFor($this->userWith('branch_manager', Scope::branch($this->branchA->id)));
        $companyAdmin = $this->headersFor($this->userWith('admin', Scope::company($this->acme->id)));
        $storekeeper = $this->headersFor($this->userWith('storekeeper', Scope::location($this->locationA->id)));
        $hrOfficer = $this->headersFor($this->userWith('hr_officer', Scope::location($this->locationA->id)));

        // Item creators read the mode (MD-02); users without master data permissions do not.
        $this->getJson('/api/v1/master-data/settings', $manager)->assertOk();
        $this->getJson('/api/v1/master-data/settings', $storekeeper)->assertOk();
        $this->getJson('/api/v1/master-data/settings', $hrOfficer)->assertForbidden();
        $this->settings(['data_type' => 'suppliers', 'mode' => 'per_company'], $manager)->assertForbidden();
        $this->settings(['data_type' => 'suppliers', 'mode' => 'per_company'], $companyAdmin)->assertForbidden();

        $this->settings(['data_type' => 'stock', 'mode' => 'sideways'])->assertUnprocessable()->assertJsonValidationErrors(['data_type', 'mode']);
        $this->settings(['data_type' => 'suppliers', 'mode' => 'per_company'])->assertOk()
            ->assertJsonPath('meta', ['assigned' => 0, 'released' => 0]);
        $this->assertSame('per_company', $this->modes()['suppliers']);

        // The same mode again changes nothing and logs nothing more.
        $this->settings(['data_type' => 'suppliers', 'mode' => 'per_company'])->assertOk();
        $this->inTenant(function () {
            $entry = AuditEntry::where('action', 'core.master_data_settings.update')->sole();
            $this->assertEquals(['data_type' => 'suppliers', 'mode' => 'shared'], $entry->before);
            $this->assertSame('per_company', $entry->after['mode']);
            $this->assertSame($this->owner->id, $entry->user_id);
        });
    }

    public function test_shared_records_are_seen_from_any_scope_and_company_records_only_where_touched(): void
    {
        $acmeCashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $globexManager = $this->headersFor($this->userWith('branch_manager', Scope::branch($this->globexBranch->id)));
        $acmeAccountant = $this->headersFor($this->userWith('accountant', Scope::company($this->acme->id)));

        $customer = $this->party(['name' => 'Group customer', 'roles' => ['customer']])->assertCreated()->json('data.id');
        $this->settings(['data_type' => 'suppliers', 'mode' => 'per_company'])->assertOk();

        // Per company: a company is required, and records are split.
        $this->party(['name' => 'Nowhere supplier', 'roles' => ['supplier']])->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $acmeSupplier = $this->party(['name' => 'Acme supplier', 'roles' => ['supplier'], 'company_id' => $this->acme->id])->assertCreated()
            ->assertJsonPath('data.shared', false)->json('data.id');
        $globexSupplier = $this->party(['name' => 'Globex supplier', 'roles' => ['supplier'], 'company_id' => $this->globex->id])->assertCreated()->json('data.id');

        $list = fn (array $headers) => array_column($this->getJson('/api/v1/parties', $headers)->assertOk()->json('data'), 'id');

        // A location cashier sees shared customers and the suppliers of the company the location is in.
        $this->assertEqualsCanonicalizing([$customer, $acmeSupplier], $list($acmeCashier));
        $this->getJson("/api/v1/parties/{$customer}", $acmeCashier)->assertOk();
        $this->getJson("/api/v1/parties/{$acmeSupplier}", $acmeCashier)->assertOk();
        $this->getJson("/api/v1/parties/{$globexSupplier}", $acmeCashier)->assertNotFound();

        $this->assertEqualsCanonicalizing([$customer, $globexSupplier], $list($globexManager));
        $this->getJson("/api/v1/parties/{$acmeSupplier}", $globexManager)->assertNotFound();
        $this->patchJson("/api/v1/parties/{$acmeSupplier}", ['name' => 'Mine'], $globexManager)->assertNotFound();
        $this->postJson("/api/v1/parties/{$acmeSupplier}/archive", [], $globexManager)->assertNotFound();

        // Creating follows the same rule: a cashier creates at the company of their location only.
        $this->party(['name' => 'Till supplier', 'roles' => ['supplier'], 'company_id' => $this->acme->id], $acmeCashier)->assertCreated();
        $this->party(['name' => 'Far supplier', 'roles' => ['supplier'], 'company_id' => $this->globex->id], $acmeCashier)->assertNotFound();

        // An Acme accountant edits Acme's supplier but cannot move it to Globex.
        $this->patchJson("/api/v1/parties/{$acmeSupplier}", ['name' => 'Acme supplier Ltd'], $acmeAccountant)->assertOk();
        $this->patchJson("/api/v1/parties/{$acmeSupplier}", ['company_id' => $this->globex->id], $acmeAccountant)
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->patchJson("/api/v1/parties/{$acmeSupplier}", ['company_id' => $this->globex->id], $this->headersFor())
            ->assertOk()->assertJsonPath('data.company_id', $this->globex->id);
        $this->getJson("/api/v1/parties/{$acmeSupplier}", $acmeAccountant)->assertNotFound();

        // A shared record can't be given a company, nor a per-company one lose it.
        $this->patchJson("/api/v1/parties/{$customer}", ['company_id' => $this->acme->id], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->patchJson("/api/v1/parties/{$globexSupplier}", ['company_id' => null], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');

        // Adding a per-company role to a shared customer names its company.
        $this->patchJson("/api/v1/parties/{$customer}", ['roles' => ['customer', 'supplier'], 'company_id' => $this->acme->id], $this->headersFor())
            ->assertOk()->assertJsonPath('data.company_id', $this->acme->id);
        // ... and dropping it shares the party again, explicitly.
        $this->patchJson("/api/v1/parties/{$customer}", ['roles' => ['customer'], 'company_id' => null], $this->headersFor())
            ->assertOk()->assertJsonPath('data.company_id', null);
    }

    public function test_a_role_change_that_moves_a_party_needs_company_id_in_the_request(): void
    {
        $this->settings(['data_type' => 'suppliers', 'mode' => 'per_company'])->assertOk();
        $customer = $this->party(['name' => 'Shared customer', 'roles' => ['customer']])->json('data.id');
        $supplier = $this->party(['name' => 'Acme supplier', 'roles' => ['supplier'], 'company_id' => $this->acme->id])->json('data.id');

        // Shared -> per company: refused without company_id, then done with it.
        $this->patchJson("/api/v1/parties/{$customer}", ['roles' => ['customer', 'supplier']], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'company_change_needs_confirmation')->assertJsonValidationErrors('company_id');
        $this->patchJson("/api/v1/parties/{$customer}", ['roles' => ['customer', 'supplier'], 'company_id' => null], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->patchJson("/api/v1/parties/{$customer}", ['roles' => ['customer', 'supplier'], 'company_id' => $this->globex->id], $this->headersFor())
            ->assertOk()->assertJsonPath('data.company_id', $this->globex->id);

        // Per company -> shared: refused without company_id, refused with a company, done with null.
        $this->patchJson("/api/v1/parties/{$supplier}", ['roles' => ['customer']], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'company_change_needs_confirmation');
        $this->patchJson("/api/v1/parties/{$supplier}", ['roles' => ['customer'], 'company_id' => $this->acme->id], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->inTenant(fn () => $this->assertSame($this->acme->id, Party::findOrFail($supplier)->company_id));
        $this->patchJson("/api/v1/parties/{$supplier}", ['roles' => ['customer'], 'company_id' => null], $this->headersFor())
            ->assertOk()->assertJsonPath('data.company_id', null);

        // A role change that keeps the party where it is needs nothing more.
        $this->patchJson("/api/v1/parties/{$supplier}", ['roles' => ['customer', 'contact']], $this->headersFor())->assertOk();
    }

    public function test_changing_a_company_party_needs_a_scope_covering_the_company(): void
    {
        // Built before any request: a request switches the default guard.
        $branchUser = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Branch party clerk', ['core.party.view', 'core.party.create', 'core.party.edit', 'core.party.archive']), Scope::branch($this->branchA->id));

            return $user;
        });
        $this->settings(['data_type' => 'suppliers', 'mode' => 'per_company'])->assertOk();
        $supplier = $this->party(['name' => 'Acme supplier', 'roles' => ['supplier'], 'company_id' => $this->acme->id])->json('data.id');
        $customer = $this->party(['name' => 'Shared customer', 'roles' => ['customer']])->json('data.id');
        $headers = $this->headersFor($branchUser);

        // View and create follow the touched rule ...
        $this->getJson("/api/v1/parties/{$supplier}", $headers)->assertOk();
        $this->party(['name' => 'Branch supplier', 'roles' => ['supplier'], 'company_id' => $this->acme->id], $headers)->assertCreated();
        // ... changing the company's party needs the company (or tenant) scope.
        $this->patchJson("/api/v1/parties/{$supplier}", ['name' => 'Renamed'], $headers)->assertForbidden();
        $this->postJson("/api/v1/parties/{$supplier}/archive", [], $headers)->assertForbidden();
        // Shared parties are changed from any scope.
        $this->patchJson("/api/v1/parties/{$customer}", ['name' => 'Renamed customer'], $headers)->assertOk();
        $this->postJson("/api/v1/parties/{$customer}/archive", [], $headers)->assertOk();

        // A user without any create permission is forbidden, even naming a company.
        $storekeeper = $this->headersFor($this->userWith('storekeeper', Scope::location($this->locationA->id)));
        $this->party(['name' => 'X', 'roles' => ['supplier'], 'company_id' => $this->acme->id], $storekeeper)->assertForbidden();
        $this->party(['name' => 'X', 'roles' => ['supplier'], 'company_id' => $this->globex->id], $storekeeper)->assertForbidden();
    }

    public function test_switching_to_per_company_clears_price_lists_of_other_companies(): void
    {
        [$acmeList, $globexList] = $this->inTenant(fn () => [
            PriceList::create(['company_id' => $this->acme->id, 'name' => 'Acme retail', 'currency' => 'KES'])->id,
            PriceList::create(['company_id' => $this->globex->id, 'name' => 'Globex retail', 'currency' => 'KES'])->id,
        ]);
        $keeps = $this->party(['name' => 'Acme priced', 'roles' => ['customer'], 'price_list_id' => $acmeList])->json('data.id');
        $loses = $this->party(['name' => 'Globex priced', 'roles' => ['customer'], 'price_list_id' => $globexList])->json('data.id');

        $this->settings(['data_type' => 'customers', 'mode' => 'per_company', 'assign_to_company_id' => $this->acme->id])
            ->assertOk()->assertJsonPath('meta.assigned', 2)->assertJsonPath('meta.price_lists_cleared', 1);

        $this->inTenant(function () use ($keeps, $loses, $globexList) {
            $this->assertSame([$keeps => true, $loses => false], [
                $keeps => Party::findOrFail($keeps)->price_list_id !== null,
                $loses => Party::findOrFail($loses)->price_list_id !== null,
            ]);
            $move = AuditEntry::where('action', 'core.party.update')->where('auditable_id', $loses)->sole();
            $this->assertSame($globexList, $move->before['price_list_id']);
            $this->assertNull($move->after['price_list_id']);
            $this->assertSame(1, AuditEntry::where('action', 'core.master_data_settings.update')->sole()->after['price_lists_cleared']);
        });
    }

    public function test_writers_and_switches_take_the_sharing_lock_before_row_locks(): void
    {
        $this->settings(['data_type' => 'suppliers', 'mode' => 'per_company'])->assertOk();
        $id = $this->party(['name' => 'Locked', 'roles' => ['customer']])->json('data.id');
        $this->party(['name' => 'Other', 'roles' => ['customer']])->assertCreated();

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });
        $position = function (string $needle) use (&$statements) {
            return collect($statements)->search(fn (string $sql) => str_contains($sql, $needle));
        };

        // Update: every party type's shared advisory lock, then the row lock.
        $this->patchJson("/api/v1/parties/{$id}", ['name' => 'Locked too'], $this->headersFor())->assertOk();
        $advisory = collect($statements)->filter(fn (string $sql) => str_contains($sql, 'pg_advisory_xact_lock_shared'))->keys();
        $this->assertCount(3, $advisory, 'customers, suppliers and employees are locked');
        $rowLock = $position('for update');
        $this->assertNotFalse($rowLock);
        $this->assertLessThan($rowLock, $advisory->max(), 'advisory locks come before the row lock');

        // Switch: the exclusive advisory lock, then the rows it changes.
        $statements = [];
        $this->settings(['data_type' => 'customers', 'mode' => 'per_company', 'assign_to_company_id' => $this->acme->id])->assertOk();
        $exclusive = $position('select pg_advisory_xact_lock(');
        $firstRowWrite = $position('update "parties"');
        $this->assertNotFalse($exclusive);
        $this->assertNotFalse($firstRowWrite);
        $this->assertLessThan($firstRowWrite, $exclusive);
    }

    public function test_switching_to_per_company_assigns_every_record_or_is_refused(): void
    {
        $a = $this->party(['name' => 'Customer one', 'roles' => ['customer']])->json('data.id');
        $b = $this->party(['name' => 'Customer two', 'roles' => ['contact']])->json('data.id');
        $supplier = $this->party(['name' => 'Supplier', 'roles' => ['supplier']])->json('data.id');
        $this->postJson("/api/v1/parties/{$b}/archive", [], $this->headersFor())->assertOk();

        $this->settings(['data_type' => 'customers', 'mode' => 'per_company'])
            ->assertUnprocessable()->assertJsonPath('code', 'records_need_company')->assertJsonPath('count', 2);
        $this->assertSame('shared', $this->modes()['customers']);

        $other = $this->otherTenant();
        $this->settings(['data_type' => 'customers', 'mode' => 'per_company', 'assign_to_company_id' => $other['company']->id])
            ->assertUnprocessable()->assertJsonValidationErrors('assign_to_company_id');

        $this->settings(['data_type' => 'customers', 'mode' => 'per_company', 'assign_to_company_id' => $this->acme->id])
            ->assertOk()->assertJsonPath('meta.assigned', 2);

        $this->inTenant(function () use ($a, $b, $supplier) {
            // No orphan: the archived one moved too; suppliers stay shared.
            $this->assertSame($this->acme->id, Party::findOrFail($a)->company_id);
            $this->assertSame($this->acme->id, Party::findOrFail($b)->company_id);
            $this->assertNull(Party::findOrFail($supplier)->company_id);
            $this->assertSame(0, Party::whereNull('company_id')->whereRaw("roles && '{customer,contact}'")->count());

            // Each move is in the record's history (MD-07).
            $move = AuditEntry::where('action', 'core.party.update')->where('auditable_id', $a)->sole();
            $this->assertSame(['company_id' => null], $move->before);
            $this->assertSame(['company_id' => $this->acme->id], $move->after);
        });

        // A Globex user no longer sees them.
        $globexCashier = $this->headersFor($this->userWith('cashier', Scope::location($this->globexLocation->id)));
        $this->getJson("/api/v1/parties/{$a}", $globexCashier)->assertNotFound();
        $this->assertSame([$supplier], array_column($this->getJson('/api/v1/parties', $globexCashier)->json('data'), 'id'));
    }

    public function test_switching_to_shared_needs_confirmation_and_keeps_other_per_company_roles(): void
    {
        $this->settings(['data_type' => 'customers', 'mode' => 'per_company'])->assertOk();
        $this->settings(['data_type' => 'suppliers', 'mode' => 'per_company'])->assertOk();
        $customer = $this->party(['name' => 'Only customer', 'roles' => ['customer'], 'company_id' => $this->globex->id])->json('data.id');
        $both = $this->party(['name' => 'Customer and supplier', 'roles' => ['customer', 'supplier'], 'company_id' => $this->globex->id])->json('data.id');

        $this->settings(['data_type' => 'customers', 'mode' => 'shared'])
            ->assertUnprocessable()->assertJsonPath('code', 'confirmation_required');
        $this->settings(['data_type' => 'customers', 'mode' => 'shared', 'confirm' => true])
            ->assertOk()->assertJsonPath('meta.released', 1);

        $this->inTenant(function () use ($customer, $both) {
            $this->assertNull(Party::findOrFail($customer)->company_id);
            $this->assertSame($this->globex->id, Party::findOrFail($both)->company_id, 'still a per-company supplier');
        });

        // Now everyone with the permission sees the customer.
        $acmeCashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $this->getJson("/api/v1/parties/{$customer}", $acmeCashier)->assertOk();
        $this->getJson("/api/v1/parties/{$both}", $acmeCashier)->assertNotFound();
    }

    public function test_a_guard_can_refuse_a_switch_and_nothing_changes(): void
    {
        $this->settings(['data_type' => 'customers', 'mode' => 'per_company'])->assertOk();
        $id = $this->party(['name' => 'Kept', 'roles' => ['customer'], 'company_id' => $this->acme->id])->json('data.id');

        app(MasterDataSharing::class)->guard(new class implements SharingSwitchGuard
        {
            public function check(string $dataType, string $to): void
            {
                throw new ApiException(422, 'duplicate_codes', 'Duplicate codes.', extra: ['duplicates' => ['X1']]);
            }
        });

        $this->settings(['data_type' => 'customers', 'mode' => 'shared', 'confirm' => true])
            ->assertUnprocessable()->assertJsonPath('code', 'duplicate_codes')->assertJsonPath('duplicates', ['X1']);

        $this->assertSame('per_company', $this->modes()['customers']);
        $this->inTenant(fn () => $this->assertSame($this->acme->id, Party::findOrFail($id)->company_id));
    }

    public function test_tax_categories_follow_the_items_mode(): void
    {
        $code = fn (Company $company) => $this->inTenant(function () use ($company) {
            app(ApplyCountryPack::class)->apply($company);

            return TaxCode::where('company_id', $company->id)->where('code', 'VAT_STD')->value('id');
        });
        $acmeCode = $code($this->acme);
        $globexCode = $code($this->globex);

        $this->postJson('/api/v1/tax-categories', ['name' => 'Acme goods', 'company_id' => $this->acme->id], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $shared = $this->postJson('/api/v1/tax-categories', ['name' => 'Goods', 'codes' => [
            ['company_id' => $this->acme->id, 'tax_code_id' => $acmeCode],
            ['company_id' => $this->globex->id, 'tax_code_id' => $globexCode],
        ]], $this->headersFor())->assertCreated()->json('data.id');

        $this->settings(['data_type' => 'items', 'mode' => 'per_company'])
            ->assertUnprocessable()->assertJsonPath('count', 1);
        $this->settings(['data_type' => 'items', 'mode' => 'per_company', 'assign_to_company_id' => $this->acme->id])
            ->assertOk()->assertJsonPath('meta.assigned', 1);

        $this->inTenant(function () use ($shared, $acmeCode, $globexCode) {
            $this->assertSame($this->acme->id, TaxCategory::findOrFail($shared)->company_id);
            // A company's category maps only its own company; the dropped default is audited.
            $this->assertSame([$this->acme->id], TaxCategoryCode::where('tax_category_id', $shared)->pluck('company_id')->all());
            $entry = AuditEntry::where('action', 'core.tax_category.codes_update')->where('auditable_id', $shared)->orderByDesc('seq')->first();
            $this->assertEqualsCanonicalizing([$this->acme->id => $acmeCode, $this->globex->id => $globexCode], $entry->before['codes']);
            $this->assertSame([$this->acme->id => $acmeCode], $entry->after['codes']);
        });

        $this->postJson('/api/v1/tax-categories', ['name' => 'Shared now?'], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
        $this->postJson('/api/v1/tax-categories', ['name' => 'Globex goods', 'company_id' => $this->globex->id], $this->headersFor())->assertCreated();

        $this->settings(['data_type' => 'items', 'mode' => 'shared', 'confirm' => true])->assertOk()->assertJsonPath('meta.released', 2);
        $this->inTenant(fn () => $this->assertSame(0, TaxCategory::whereNotNull('company_id')->count()));
    }

    public function test_the_setting_row_is_tenant_scoped(): void
    {
        $this->settings(['data_type' => 'employees', 'mode' => 'per_company'])->assertOk();
        $other = $this->otherTenant();

        $this->assertSame('shared', collect($this->getJson('/api/v1/master-data/settings', $this->headersFor($other['user']))->json('data'))
            ->firstWhere('data_type', 'employees')['mode']);
        $this->asTenant($other['user']->tenant_id, fn () => $this->assertSame(0, MasterDataSetting::count()));
        $this->inTenant(fn () => $this->assertSame(1, MasterDataSetting::count()));
    }
}
