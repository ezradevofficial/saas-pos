<?php

namespace Modules\Pharmacy\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Pharmacy\Models\RegisterEntry;
use Modules\Pharmacy\Tests\Concerns\BuildsPharmacy;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PHA-18, PHA-19: the controlled-drug register is an append-only ledger per
// item and place with a running balance, printable and exportable; a
// correction is a new entry that needs its own permission and a reason.
class RegisterApiTest extends TestCase
{
    use BuildsPharmacy, RefreshTenantDatabase;

    private User $pharmacist;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPharmacy();
        $this->pharmacist = $this->userWith('pharmacist', Scope::location($this->locationA->id));
        $this->technician = $this->userWith('pharmacy_technician', Scope::location($this->locationA->id));
    }

    private function receive(string $qty, string $batch = 'M-1'): void
    {
        $this->postJson('/api/v1/pharmacy/receipts', [
            'location_id' => $this->locationA->id,
            'lines' => [['item_id' => $this->morphine->id, 'qty' => $qty, 'batch_number' => $batch, 'expires_on' => $this->inDays(300), 'unit_cost_minor' => '900', 'currency' => 'KES']],
        ], $this->headersFor($this->technician))->assertCreated();
    }

    public function test_receipts_transfers_and_write_offs_are_entered_with_a_running_balance(): void
    {
        $this->receive('10');
        $this->receive('5', 'M-2');
        $this->postJson('/api/v1/pharmacy/transfers', [
            'from_location_id' => $this->locationA->id, 'to_location_id' => $this->locationB->id,
            'lines' => [['item_id' => $this->morphine->id, 'qty' => '4']],
        ], $this->headersFor($this->owner))->assertCreated();

        $rows = $this->getJson("/api/v1/pharmacy/register?item_id={$this->morphine->id}&location={$this->locationA->id}", $this->headersFor($this->pharmacist))->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame([[1, 'receipt', '10', '10'], [2, 'receipt', '5', '15'], [3, 'transfer_out', '-4', '11']],
            array_map(fn (array $row) => [$row['entry_no'], $row['kind'], $row['qty'], $row['balance']], $rows->json('data')));
        // The other end has its own register.
        $this->getJson("/api/v1/pharmacy/register?location={$this->locationB->id}", $this->headersFor($this->owner))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'transfer_in')->assertJsonPath('data.0.balance', '4');
    }

    public function test_the_database_refuses_to_change_or_remove_an_entry(): void
    {
        $this->receive('10');
        $entry = $this->inTenant(fn () => RegisterEntry::sole());

        foreach ([
            fn () => DB::table('pharmacy_register_entries')->where('id', $entry->id)->update(['qty' => '99']),
            fn () => DB::table('pharmacy_register_entries')->where('id', $entry->id)->delete(),
            fn () => DB::table('pharmacy_lot_movements')->update(['qty' => '99']),
        ] as $change) {
            try {
                $this->inTenant(fn () => DB::transaction($change));
                $this->fail('A change to the ledger was accepted.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }

        $this->assertSame('10.000000', (string) $this->inTenant(fn () => RegisterEntry::sole()->qty));
    }

    public function test_a_correction_is_a_new_entry_that_needs_the_permission_and_a_reason(): void
    {
        $this->receive('10');
        $body = ['item_id' => $this->morphine->id, 'location_id' => $this->locationA->id, 'qty' => '-1', 'reason' => 'Broken ampoule counted at stocktake'];

        $this->postJson('/api/v1/pharmacy/register/adjustments', $body, $this->headersFor($this->technician))->assertForbidden();
        $this->postJson('/api/v1/pharmacy/register/adjustments', [...$body, 'reason' => ''], $this->headersFor($this->pharmacist))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/pharmacy/register/adjustments', [...$body, 'qty' => '0'], $this->headersFor($this->pharmacist))->assertUnprocessable()->assertJsonValidationErrors('qty');
        $this->postJson('/api/v1/pharmacy/register/adjustments', [...$body, 'item_id' => $this->para->id], $this->headersFor($this->pharmacist))->assertUnprocessable()->assertJsonPath('code', 'item_not_controlled');

        $this->postJson('/api/v1/pharmacy/register/adjustments', $body, $this->headersFor($this->pharmacist))->assertCreated()
            ->assertJsonPath('data.kind', 'adjustment')->assertJsonPath('data.balance', '9')->assertJsonPath('data.reason', 'Broken ampoule counted at stocktake')
            ->assertJsonPath('data.recorded_by.id', $this->pharmacist->id);
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::where('action', 'pharmacy.register.adjust')->count()));
        $this->assertSame(2, $this->inTenant(fn () => RegisterEntry::count()));
    }

    public function test_the_register_prints_with_opening_and_closing_balances_and_exports(): void
    {
        $this->receive('10');
        $this->postJson('/api/v1/pharmacy/register/adjustments', ['item_id' => $this->morphine->id, 'location_id' => $this->locationA->id, 'qty' => '-2', 'reason' => 'Count'], $this->headersFor($this->pharmacist))->assertCreated();

        $print = $this->getJson("/api/v1/pharmacy/register/print?item_id={$this->morphine->id}&location_id={$this->locationA->id}", $this->headersFor($this->pharmacist))->assertOk()
            ->assertJsonPath('data.item.code', 'MORPH')->assertJsonPath('data.location.name', 'Outlet A')
            ->assertJsonPath('data.opening_balance', '0')->assertJsonPath('data.closing_balance', '8')->assertJsonCount(2, 'data.entries');
        $this->assertNotNull($print->json('data.printed_at'));
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::where('action', 'pharmacy.register.print')->count()));

        // From tomorrow: nothing in the period, and the balance carried over is the opening balance.
        $tomorrow = $this->inDays(1);
        $this->getJson("/api/v1/pharmacy/register/print?item_id={$this->morphine->id}&location_id={$this->locationA->id}&from={$tomorrow}", $this->headersFor($this->pharmacist))->assertOk()
            ->assertJsonPath('data.opening_balance', '8')->assertJsonPath('data.closing_balance', '8')->assertJsonCount(0, 'data.entries');

        $this->getJson("/api/v1/pharmacy/register/print?item_id={$this->morphine->id}&location_id={$this->locationA->id}", $this->headersFor($this->technician))->assertForbidden();

        $csv = $this->get('/api/v1/pharmacy/register?format=csv', $this->headersFor($this->pharmacist))->assertOk();
        $this->assertStringContainsString('Morphine 10 mg (MORPH)', $csv->streamedContent());
        $this->assertStringContainsString('Correction', $csv->streamedContent());
    }

    public function test_it_is_scoped_to_the_readers_places_and_the_tenant(): void
    {
        $this->receive('10');
        $elsewhere = $this->userWith('pharmacist', Scope::location($this->locationB->id));
        $this->getJson('/api/v1/pharmacy/register', $this->headersFor($elsewhere))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/pharmacy/register/print?item_id={$this->morphine->id}&location_id={$this->locationA->id}", $this->headersFor($elsewhere))->assertForbidden();

        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('pharmacy'));
        $this->getJson('/api/v1/pharmacy/register', $this->headersFor($other['user']))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/pharmacy/register?item_id={$this->morphine->id}", $this->headersFor($other['user']))->assertUnprocessable();
    }
}
