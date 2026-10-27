<?php

namespace Modules\Pharmacy\Tests;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Modules\Pharmacy\Events\RecallIssued;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Tests\Concerns\BuildsPharmacy;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PHA-22, PHA-23: a recall blocks a batch everywhere at once, lists who bought
// it (refunds and voids netted) and is lifted with a reason.
class RecallsTest extends TestCase
{
    use BuildsPharmacy, RefreshTenantDatabase;

    private User $pharmacist;

    private User $technician;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();

        $this->setUpPharmacy();
        // A recall reaches the whole company, so the pharmacist who issues it holds the role company-wide.
        $this->pharmacist = $this->userWith('pharmacist', Scope::company($this->acme->id));
        $this->technician = $this->userWith('pharmacy_technician', Scope::location($this->locationA->id));
        $this->ranges()->assertOk();
        $this->shift = $this->openShift();
    }

    public function test_a_recall_blocks_the_batch_at_every_place_and_is_lifted_with_a_reason(): void
    {
        Event::fake([RecallIssued::class]);
        $a = $this->lot($this->para, 'BAD-1', 100, '5');
        $b = $this->lot($this->para, 'BAD-1', 100, '3', $this->locationB->id);
        $other = $this->lot($this->para, 'GOOD-1', 100, '3');
        $this->assertSame($a->id, $b->id, 'one lot, stock at two places');

        $recall = $this->postJson('/api/v1/pharmacy/recalls', ['item_id' => $this->para->id, 'batch_number' => 'bad-1', 'reason' => 'Contamination found by the maker', 'reference' => 'REF-7'], $this->headersFor($this->pharmacist))
            ->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.lots_count', 1)->assertJsonPath('data.item.code', 'PARA')->json('data');
        Event::assertDispatched(RecallIssued::class, fn (RecallIssued $e) => $e->recallId === $recall['id'] && $e->lotIds === [$a->id]);

        $this->assertSame([$recall['id'], null], $this->inTenant(fn () => [Lot::find($a->id)->recall_id, Lot::find($other->id)->recall_id]));
        $this->getJson("/api/v1/pharmacy/lots/{$a->id}", $this->headersFor($this->pharmacist))->assertJsonPath('data.state', 'recalled');
        // Neither the suggestion nor a quarantine release brings it back.
        $this->getJson("/api/v1/pharmacy/fefo?item_id={$this->para->id}&location_id={$this->locationA->id}", $this->headersFor($this->technician))
            ->assertJsonPath('data.blocked.0.reason', 'lot_recalled')->assertJsonPath('data.lots.0.batch_number', 'GOOD-1');
        $this->postJson("/api/v1/pharmacy/lots/{$a->id}/release", ['reason' => 'x'], $this->headersFor($this->pharmacist))->assertStatus(409)->assertJsonPath('code', 'lot_recalled');

        // A second open recall of the same batch is refused.
        $this->postJson('/api/v1/pharmacy/recalls', ['item_id' => $this->para->id, 'batch_number' => 'BAD-1', 'reason' => 'Again'], $this->headersFor($this->pharmacist))->assertStatus(409)->assertJsonPath('code', 'recall_open');

        // A lot of the batch received after the recall is blocked from the start.
        $this->postJson('/api/v1/pharmacy/receipts', ['location_id' => $this->locationA->id, 'lines' => [['item_id' => $this->para->id, 'qty' => '2', 'batch_number' => 'BAD-1', 'expires_on' => $this->inDays(100), 'unit_cost_minor' => '100', 'currency' => 'KES']]], $this->headersFor($this->technician))->assertCreated();

        $this->postJson("/api/v1/pharmacy/recalls/{$recall['id']}/lift", ['reason' => 'The maker withdrew the recall'], $this->headersFor($this->technician))->assertForbidden();
        $this->postJson("/api/v1/pharmacy/recalls/{$recall['id']}/lift", ['reason' => 'The maker withdrew the recall'], $this->headersFor($this->pharmacist))->assertOk()
            ->assertJsonPath('data.status', 'lifted')->assertJsonPath('data.lift_reason', 'The maker withdrew the recall');
        $this->getJson("/api/v1/pharmacy/lots/{$a->id}", $this->headersFor($this->pharmacist))->assertJsonPath('data.state', 'active');
        $this->postJson("/api/v1/pharmacy/recalls/{$recall['id']}/lift", ['reason' => 'x'], $this->headersFor($this->pharmacist))->assertStatus(409)->assertJsonPath('code', 'recall_lifted');
        $this->getJson('/api/v1/pharmacy/recalls?status=lifted', $this->headersFor($this->technician))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/pharmacy/recalls?status=open', $this->headersFor($this->technician))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_it_lists_who_bought_the_batch_net_of_what_came_back(): void
    {
        $lot = $this->lot($this->para, 'BAD-2', 100, '20');
        $amina = $this->customer();
        $this->sellDrugs($this->shift, 1, [$this->drugLine($this->para, '3')], [], ['customer_id' => $amina->id]);
        $this->sellDrugs($this->shift, 2, [$this->drugLine($this->para, '2')]);
        $returned = $this->sellDrugs($this->shift, 3, [$this->drugLine($this->para, '4')], [], ['customer_id' => $amina->id]);

        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $this->id(), 'sale_id' => $returned['id'], 'voided_by_id' => $this->owner->id,
            'voided_at' => now()->toIso8601String(), 'reason' => 'Mistake', 'override' => $this->override($manager->id),
        ]]], $this->tillHeaders())->assertOk();

        $recall = $this->postJson('/api/v1/pharmacy/recalls', ['item_id' => $this->para->id, 'batch_number' => 'BAD-2', 'reason' => 'Mislabelled'], $this->headersFor($this->pharmacist))->assertCreated()->json('data.id');
        $this->assertSame('15', $this->lotQty($lot));

        $this->getJson("/api/v1/pharmacy/recalls/{$recall}/customers", $this->headersFor($this->pharmacist))->assertOk()
            ->assertJsonCount(1, 'data.customers')
            ->assertJsonPath('data.customers.0.customer.name', 'Amina')
            ->assertJsonPath('data.customers.0.qty', '3')
            ->assertJsonPath('data.customers.0.receipt_number', 'R-L01-000001')
            ->assertJsonPath('data.unattributed.sales', 1)
            ->assertJsonPath('data.unattributed.qty', '2');
    }

    public function test_recalls_need_their_permissions_and_stay_in_the_tenant(): void
    {
        $this->postJson('/api/v1/pharmacy/recalls', ['item_id' => $this->para->id, 'batch_number' => 'X', 'reason' => 'x'], $this->headersFor($this->technician))->assertForbidden();
        $this->postJson('/api/v1/pharmacy/recalls', ['item_id' => $this->para->id, 'batch_number' => '', 'reason' => ''], $this->headersFor($this->pharmacist))->assertUnprocessable()->assertJsonValidationErrors(['batch_number', 'reason']);

        $this->lot($this->para, 'X', 100, '1');
        $recall = $this->postJson('/api/v1/pharmacy/recalls', ['item_id' => $this->para->id, 'batch_number' => 'X', 'reason' => 'x'], $this->headersFor($this->pharmacist))->assertCreated()->json('data.id');
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('pharmacy'));
        $this->getJson("/api/v1/pharmacy/recalls/{$recall}", $this->headersFor($other['user']))->assertNotFound();
        $this->getJson("/api/v1/pharmacy/recalls/{$recall}/customers", $this->headersFor($other['user']))->assertNotFound();
        $this->postJson("/api/v1/pharmacy/recalls/{$recall}/lift", ['reason' => 'x'], $this->headersFor($other['user']))->assertNotFound();
        $this->postJson('/api/v1/pharmacy/recalls', ['item_id' => $this->para->id, 'batch_number' => 'X', 'reason' => 'x'], $this->headersFor($other['user']))->assertUnprocessable()->assertJsonValidationErrors('item_id');
    }

    // PHA-22, RBAC-04: a recall blocks the batch in every company that has it, so the right must reach them all.
    public function test_issuing_and_lifting_need_the_right_for_every_company_the_recall_reaches(): void
    {
        $this->lot($this->para, 'WIDE-1', 100, '5');
        $atBranch = $this->userWith('pharmacist', Scope::branch($this->branchA->id));
        $body = ['item_id' => $this->para->id, 'batch_number' => 'WIDE-1', 'reason' => 'Contamination'];

        $this->postJson('/api/v1/pharmacy/recalls', $body, $this->headersFor($atBranch))->assertForbidden();
        // Nothing to name the company by: tenant-wide only.
        $this->postJson('/api/v1/pharmacy/recalls', [...$body, 'batch_number' => 'NOBODY-HAS-IT'], $this->headersFor($this->pharmacist))->assertForbidden();
        $recall = $this->postJson('/api/v1/pharmacy/recalls', $body, $this->headersFor($this->pharmacist))->assertCreated()->json('data.id');

        $this->postJson("/api/v1/pharmacy/recalls/{$recall}/lift", ['reason' => 'Withdrawn'], $this->headersFor($atBranch))->assertForbidden();
        $this->postJson("/api/v1/pharmacy/recalls/{$recall}/lift", ['reason' => 'Withdrawn'], $this->headersFor($this->pharmacist))->assertOk();
    }

    // PHA-23, PHA-28: who bought the batch is personal data, and read only for the places the reader sees recalls at.
    public function test_the_customer_list_follows_the_readers_places_and_the_customer_data_right(): void
    {
        $this->lot($this->para, 'BAD-3', 100, '20');
        $this->lot($this->para, 'BAD-3', 100, '10', $this->locationB->id);
        $amina = $this->customer();
        $this->sellDrugs($this->shift, 1, [$this->drugLine($this->para, '3')], [], ['customer_id' => $amina->id]);
        $recall = $this->postJson('/api/v1/pharmacy/recalls', ['item_id' => $this->para->id, 'batch_number' => 'BAD-3', 'reason' => 'Mislabelled'], $this->headersFor($this->pharmacist))->assertCreated()->json('data.id');
        $url = "/api/v1/pharmacy/recalls/{$recall}/customers";

        // Seeing recalls is not seeing customers: the sale is listed, the person is not.
        $this->getJson($url, $this->headersFor($this->technician))->assertOk()
            ->assertJsonCount(1, 'data.customers')->assertJsonPath('data.customers.0.customer', null)->assertJsonPath('data.customers.0.qty', '3');
        // A reader at another place sees none of this place's sales.
        $elsewhere = $this->userWith('pharmacist', Scope::location($this->locationB->id));
        $this->getJson($url, $this->headersFor($elsewhere))->assertOk()->assertJsonCount(0, 'data.customers')->assertJsonPath('data.unattributed.sales', 0);
        $this->getJson($url, $this->headersFor($this->pharmacist))->assertOk()->assertJsonPath('data.customers.0.customer.name', 'Amina');
    }
}
