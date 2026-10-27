<?php

namespace Modules\Pharmacy\Tests;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Inventory\Contracts\StockWrittenOff;
use Modules\Pharmacy\Models\LotMovement;
use Modules\Pharmacy\Models\RegisterEntry;
use Modules\Pharmacy\Models\WriteOff;
use Modules\Pharmacy\Register\ControlledRegister;
use Modules\Pharmacy\Stock\Lots;
use Modules\Pharmacy\Support\Qty;
use Modules\Pharmacy\Tests\Concerns\BuildsPharmacy;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PHA-12, WF-01, APR-07: write-offs of expired or damaged stock go through a
// flow, are approved by a branch manager (never the requester), reduce the
// lots, the register and (through Inventory's contract) Inventory, and are audited.
class WriteOffsTest extends TestCase
{
    use BuildsPharmacy, RefreshTenantDatabase;

    private User $manager;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpPharmacy();
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->technician = $this->userWith('pharmacy_technician', Scope::location($this->locationA->id));
    }

    private function approve(string $id, ?User $by = null): void
    {
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $id)->sole());
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($by ?? $this->manager))->assertOk();
    }

    private function request(array $lines, string $reason = 'expired', ?User $by = null): TestResponse
    {
        return $this->postJson('/api/v1/pharmacy/write-offs', ['location_id' => $this->locationA->id, 'reason' => $reason, 'note' => 'End of month', 'lines' => $lines], $this->headersFor($by ?? $this->technician));
    }

    public function test_an_expired_lot_is_written_off_when_the_manager_approves(): void
    {
        Event::fake([StockWrittenOff::class]);
        $old = $this->lot($this->para, 'OLD', -10, '8');

        $writeOff = $this->request([['lot_id' => $old->id, 'qty' => '5']])->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.reason', 'expired')->assertJsonPath('data.lines.0.qty', '5')->assertJsonPath('data.lines.0.lot.batch_number', 'OLD')->json('data');
        $this->assertStringStartsWith('WO-', $writeOff['number']);
        $this->assertSame('8', $this->lotQty($old), 'nothing leaves before the decision');

        // APR-07: the requester never decides their own request.
        $this->postJson('/api/v1/approvals/'.$this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $writeOff['id'])->sole()->id).'/approve', [], $this->headersFor($this->technician))->assertForbidden();
        $this->approve($writeOff['id']);

        $this->getJson("/api/v1/pharmacy/write-offs/{$writeOff['id']}", $this->headersFor($this->technician))->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertSame('3', $this->lotQty($old));
        $this->assertSame(['-5.000000', 'expired'], $this->inTenant(fn () => [(string) LotMovement::query()->where('kind', 'write_off')->sole()->qty, LotMovement::query()->where('kind', 'write_off')->sole()->note]));
        Event::assertDispatched(StockWrittenOff::class, fn (StockWrittenOff $e) => $e->writeOffId === $writeOff['id'] && $e->locationId === $this->locationA->id
            && $e->lines[0]['qty'] === '5' && $e->lines[0]['batch_number'] === 'OLD' && $e->reference === $writeOff['number']);
        $this->assertEqualsCanonicalizing(['pharmacy.write_off.create', 'pharmacy.write_off.post'], $this->inTenant(fn () => AuditEntry::where('action', 'like', 'pharmacy.write_off.%')->pluck('action')->all()));
    }

    public function test_a_controlled_drug_write_off_is_entered_in_the_register(): void
    {
        $lot = $this->lot($this->morphine, 'C-9', -1, '6');
        $this->inTenant(fn () => app(ControlledRegister::class)->append($this->acme->id, $this->locationA->id, $this->morphine->id, RegisterEntry::RECEIPT, Qty::of('6'), 'test.receipt', (string) Str::uuid7(), null, $lot));

        $writeOff = $this->request([['lot_id' => $lot->id, 'qty' => '6']])->assertCreated()->json('data.id');
        $this->approve($writeOff);

        $entry = $this->inTenant(fn () => RegisterEntry::query()->where('kind', 'write_off')->sole());
        $this->assertSame(['-6.000000', '0.000000', 'C-9', 'expired'], [(string) $entry->qty, (string) $entry->balance, $entry->batch_number, $entry->reason]);
    }

    public function test_it_refuses_what_cannot_be_written_off_and_rejection_or_cancellation_moves_nothing(): void
    {
        $fresh = $this->lot($this->para, 'FRESH', 100, '5');
        $old = $this->lot($this->para, 'OLD', -2, '5');

        $this->request([['lot_id' => $fresh->id, 'qty' => '1']])->assertUnprocessable()->assertJsonPath('code', 'lot_not_expired');
        $this->request([['lot_id' => $fresh->id, 'qty' => '1']], 'damaged')->assertCreated();
        $this->request([['lot_id' => $old->id, 'qty' => '6']])->assertUnprocessable()->assertJsonPath('code', 'insufficient_stock');
        $this->request([['lot_id' => $old->id, 'qty' => '3'], ['lot_id' => $old->id, 'qty' => '3']])->assertUnprocessable()->assertJsonPath('code', 'insufficient_stock');
        $this->request([['lot_id' => $old->id, 'qty' => '0']])->assertUnprocessable()->assertJsonValidationErrors('lines.0.qty');
        $this->request([['lot_id' => $old->id, 'qty' => '1']], 'theft')->assertUnprocessable()->assertJsonValidationErrors('reason');

        $rejected = $this->request([['lot_id' => $old->id, 'qty' => '2']])->assertCreated()->json('data.id');
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $rejected)->sole());
        $this->postJson("/api/v1/approvals/{$approval->id}/reject", ['comment' => 'No'], $this->headersFor($this->manager))->assertOk();
        $this->getJson("/api/v1/pharmacy/write-offs/{$rejected}", $this->headersFor($this->manager))->assertJsonPath('data.status', 'rejected');

        $cancelled = $this->request([['lot_id' => $old->id, 'qty' => '2']])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/pharmacy/write-offs/{$cancelled}/cancel", ['reason' => 'Counted again'], $this->headersFor($this->manager))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/pharmacy/write-offs/{$cancelled}/cancel", ['reason' => 'Again'], $this->headersFor($this->manager))->assertStatus(409)->assertJsonPath('code', 'document_not_open');
        $this->assertSame('5', $this->lotQty($old));
    }

    public function test_stock_that_left_meanwhile_fails_the_write_off_instead_of_going_negative(): void
    {
        $lot = $this->lot($this->para, 'OLD', -2, '5');
        $writeOff = $this->request([['lot_id' => $lot->id, 'qty' => '5']])->assertCreated()->json('data.id');
        $this->inTenant(fn () => app(Lots::class)->move($lot, $this->locationA->id, LotMovement::ADJUSTMENT, BigDecimal::of('-4'), 'test.adjust', (string) Str::uuid7()));

        $this->approve($writeOff);
        $this->getJson("/api/v1/pharmacy/write-offs/{$writeOff}", $this->headersFor($this->manager))->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure', 'insufficient_stock');
        $this->assertSame('1', $this->lotQty($lot));
        $this->assertSame(0, $this->inTenant(fn () => WriteOff::query()->where('status', 'posted')->count()));
    }

    public function test_permissions_places_and_tenants(): void
    {
        $lot = $this->lot($this->para, 'OLD', -2, '5');
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->request([['lot_id' => $lot->id, 'qty' => '1']], 'expired', $cashier)->assertForbidden();

        $writeOff = $this->request([['lot_id' => $lot->id, 'qty' => '1']])->assertCreated()->json('data.id');
        $elsewhere = $this->userWith('pharmacy_technician', Scope::location($this->locationB->id));
        $this->getJson("/api/v1/pharmacy/write-offs/{$writeOff}", $this->headersFor($elsewhere))->assertNotFound();
        $this->postJson("/api/v1/pharmacy/write-offs/{$writeOff}/cancel", ['reason' => 'x'], $this->headersFor($elsewhere))->assertNotFound();
        $this->getJson('/api/v1/pharmacy/write-offs', $this->headersFor($elsewhere))->assertOk()->assertJsonCount(0, 'data');
        // A technician cannot cancel what only a holder of the cancel permission may.
        $this->postJson("/api/v1/pharmacy/write-offs/{$writeOff}/cancel", ['reason' => 'x'], $this->headersFor($this->technician))->assertForbidden();

        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('pharmacy'));
        $this->getJson("/api/v1/pharmacy/write-offs/{$writeOff}", $this->headersFor($other['user']))->assertNotFound();
        $this->postJson('/api/v1/pharmacy/write-offs', ['location_id' => $this->locationA->id, 'reason' => 'expired', 'lines' => [['lot_id' => $lot->id, 'qty' => '1']]], $this->headersFor($other['user']))->assertUnprocessable();

        $this->get('/api/v1/pharmacy/write-offs?format=csv', $this->headersFor($this->manager))->assertOk();
    }
}
