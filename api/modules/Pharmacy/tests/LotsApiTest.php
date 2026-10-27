<?php

namespace Modules\Pharmacy\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Modules\Inventory\Contracts\GoodsReceived;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\LotMovement;
use Modules\Pharmacy\Stock\Lots;
use Modules\Pharmacy\Tests\Concerns\BuildsPharmacy;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PHA-04 to PHA-09, PHA-11: lots, receiving, first-expiry-first-out, the
// near-expiry warning, quarantine; with permission and other-tenant refusals.
class LotsApiTest extends TestCase
{
    use BuildsPharmacy, RefreshTenantDatabase;

    private User $pharmacist;

    private User $technician;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPharmacy();
        $this->pharmacist = $this->userWith('pharmacist', Scope::branch($this->branchA->id));
        $this->technician = $this->userWith('pharmacy_technician', Scope::location($this->locationA->id));
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
    }

    private function receipt(array $line, ?User $by = null, ?string $location = null): TestResponse
    {
        return $this->postJson('/api/v1/pharmacy/receipts', [
            'location_id' => $location ?? $this->locationA->id,
            'reference' => 'GRN-1',
            'lines' => [array_replace([
                'item_id' => $this->amox->id, 'qty' => '10', 'batch_number' => 'B-100', 'expires_on' => $this->inDays(200),
                'unit_cost_minor' => '1500', 'currency' => 'KES',
            ], $line)],
        ], $this->headersFor($by ?? $this->technician));
    }

    public function test_receiving_makes_the_lot_its_stock_and_tells_inventory(): void
    {
        Event::fake([GoodsReceived::class]);

        $response = $this->receipt(['manufactured_on' => $this->inDays(-30)])->assertCreated()
            ->assertJsonPath('data.lines.0.lot.batch_number', 'B-100')
            ->assertJsonPath('data.lines.0.qty', '10');
        $lot = $this->inTenant(fn () => Lot::sole());

        $this->assertSame([$this->inDays(200), $this->inDays(-30), 'active'], [$lot->expires_on->toDateString(), $lot->manufactured_on->toDateString(), $lot->status]);
        $this->assertSame('10', $this->lotQty($lot));
        $this->assertSame(['receipt', '10.000000'], $this->inTenant(fn () => [LotMovement::sole()->kind, (string) LotMovement::sole()->qty]));
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::where('action', 'pharmacy.receipt.create')->count()));

        // PHA-06: Inventory gets the batch, the expiry and the cost (1,500 minor × 10).
        Event::assertDispatched(GoodsReceived::class, fn (GoodsReceived $e) => $e->receiptId === $response->json('data.id') && $e->lines[0]['batch_number'] === 'B-100'
            && $e->lines[0]['expires_on'] === $this->inDays(200) && $e->lines[0]['cost_minor'] === '15000' && $e->lines[0]['currency'] === 'KES');
    }

    public function test_a_quantity_in_another_unit_is_stored_in_the_base_unit_and_the_same_batch_adds_up(): void
    {
        $this->receipt(['item_id' => $this->para->id, 'uom_id' => $this->box->id, 'qty' => '2', 'batch_number' => 'P-1'])->assertCreated()->assertJsonPath('data.lines.0.qty', '24');
        $this->receipt(['item_id' => $this->para->id, 'qty' => '6', 'batch_number' => 'p-1'])->assertCreated();

        $lot = $this->inTenant(fn () => Lot::sole());
        $this->assertSame('30', $this->lotQty($lot));
    }

    public function test_receiving_refuses_expired_stock_unknown_dates_untracked_items_and_a_changed_expiry(): void
    {
        $this->receipt(['expires_on' => $this->inDays(-1)])->assertUnprocessable()->assertJsonPath('code', 'lot_expired');
        $this->receipt(['manufactured_on' => $this->inDays(300)])->assertUnprocessable()->assertJsonValidationErrors('lines.0.manufactured_on');
        $this->receipt(['item_id' => $this->plain->id])->assertUnprocessable()->assertJsonPath('code', 'item_not_tracked');
        $this->receipt([])->assertCreated();
        $this->receipt(['expires_on' => $this->inDays(201)])->assertUnprocessable()->assertJsonPath('code', 'lot_dates_differ');
        $this->assertSame(1, $this->inTenant(fn () => Lot::count()));
    }

    public function test_only_those_who_hold_the_permission_where_they_work_may_receive(): void
    {
        $this->receipt([], $this->cashier)->assertForbidden();
        $elsewhere = $this->userWith('pharmacy_technician', Scope::location($this->locationB->id));
        $this->receipt([], $elsewhere)->assertForbidden();
        $this->receipt([], $elsewhere, $this->locationB->id)->assertCreated();
        // The company-wide pharmacist (a branch role) reaches only that branch's places.
        $this->receipt([], $this->pharmacist, $this->locationB->id)->assertForbidden();
        $this->receipt([], $this->pharmacist)->assertCreated();
    }

    public function test_another_tenants_ids_do_not_exist(): void
    {
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('pharmacy'));

        $this->receipt([], null, $other['location']->id)->assertUnprocessable()->assertJsonValidationErrors('location_id');
        $lot = $this->lot($this->amox, 'MINE', 90, '5');
        $this->getJson('/api/v1/pharmacy/lots/'.$lot->id, $this->headersFor($other['user']))->assertNotFound();
        $this->getJson('/api/v1/pharmacy/lots', $this->headersFor($other['user']))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_first_expiry_is_suggested_and_blocked_lots_are_never_offered(): void
    {
        $late = $this->lot($this->amox, 'LATE', 200, '5');
        $soon = $this->lot($this->amox, 'SOON', 30, '3');
        $this->lot($this->amox, 'OLD', -2, '4');
        $held = $this->lot($this->amox, 'HELD', 10, '2');
        $this->inTenant(fn () => app(Lots::class)->quarantine($held, 'Damaged pack', null));

        $this->getJson("/api/v1/pharmacy/fefo?item_id={$this->amox->id}&location_id={$this->locationA->id}&qty=6", $this->headersFor($this->technician))->assertOk()
            ->assertJsonPath('data.lots.0.batch_number', 'SOON')
            ->assertJsonPath('data.lots.0.take', '3')
            // PHA-09: inside the near-expiry window (90 days by default) it warns, and still sells.
            ->assertJsonPath('data.lots.0.warn', 'near_expiry')
            ->assertJsonPath('data.lots.1.batch_number', 'LATE')
            ->assertJsonPath('data.lots.1.take', '3')
            ->assertJsonPath('data.lots.1.warn', null)
            ->assertJsonPath('data.shortfall', '0')
            ->assertJsonPath('data.blocked.0.batch_number', 'OLD')
            ->assertJsonPath('data.blocked.0.reason', 'lot_expired')
            ->assertJsonPath('data.blocked.1.batch_number', 'HELD')
            ->assertJsonPath('data.blocked.1.reason', 'lot_quarantined');

        // PHA-02: the window is the tenant's setting; a lot closer to expiry than `min_sale_days` is refused.
        $this->settings(['near_expiry_days' => 10, 'min_sale_days' => 40]);
        $this->getJson("/api/v1/pharmacy/fefo?item_id={$this->amox->id}&location_id={$this->locationA->id}&qty=9", $this->headersFor($this->technician))->assertOk()
            ->assertJsonPath('data.lots.0.batch_number', 'LATE')
            ->assertJsonPath('data.lots.0.warn', null)
            ->assertJsonPath('data.shortfall', '4')
            ->assertJsonPath('data.blocked.2.reason', 'lot_too_close');
        $this->assertNotNull($late->id.$soon->id);
    }

    public function test_the_stock_list_filters_by_state_and_exports(): void
    {
        $this->lot($this->amox, 'ACTIVE', 300, '5');
        $this->lot($this->amox, 'NEAR', 30, '3');
        $this->lot($this->amox, 'GONE', -5, '2');
        $headers = $this->headersFor($this->technician);

        $this->getJson('/api/v1/pharmacy/lots', $headers)->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.batch_number', 'GONE');
        $this->getJson('/api/v1/pharmacy/lots?state=near_expiry', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.batch_number', 'NEAR');
        $this->getJson('/api/v1/pharmacy/lots?state=expired', $headers)->assertOk()->assertJsonPath('data.0.state', 'expired')->assertJsonPath('data.0.days_left', -5);
        $this->getJson('/api/v1/pharmacy/lots?search=amox&expires_within=60', $headers)->assertOk()->assertJsonCount(2, 'data');
        $this->get('/api/v1/pharmacy/lots?format=csv', $headers)->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->getJson('/api/v1/pharmacy/lots', $this->headersFor($this->cashier))->assertForbidden();
    }

    public function test_quarantine_and_release_are_audited_and_a_recalled_lot_cannot_be_released(): void
    {
        $lot = $this->lot($this->amox, 'Q-1', 100, '5');

        $this->postJson("/api/v1/pharmacy/lots/{$lot->id}/quarantine", ['reason' => 'Torn packaging'], $this->headersFor($this->technician))->assertForbidden();
        $this->postJson("/api/v1/pharmacy/lots/{$lot->id}/quarantine", ['reason' => 'Torn packaging'], $this->headersFor($this->pharmacist))->assertOk()
            ->assertJsonPath('data.state', 'quarantined')->assertJsonPath('data.quarantine_reason', 'Torn packaging');
        $this->postJson("/api/v1/pharmacy/lots/{$lot->id}/release", ['reason' => 'Checked'], $this->headersFor($this->pharmacist))->assertOk()->assertJsonPath('data.state', 'active');
        $this->postJson("/api/v1/pharmacy/lots/{$lot->id}/release", ['reason' => 'Again'], $this->headersFor($this->pharmacist))->assertStatus(409)->assertJsonPath('code', 'lot_not_quarantined');
        $this->assertSame(['pharmacy.lot.quarantine', 'pharmacy.lot.release'], $this->inTenant(fn () => AuditEntry::where('module', 'pharmacy')->whereIn('action', ['pharmacy.lot.quarantine', 'pharmacy.lot.release'])->orderBy('seq')->pluck('action')->all()));
        $this->getJson("/api/v1/pharmacy/lots/{$lot->id}", $this->headersFor($this->technician))->assertOk()->assertJsonPath('data.balances.0.qty', '5');
    }
}
