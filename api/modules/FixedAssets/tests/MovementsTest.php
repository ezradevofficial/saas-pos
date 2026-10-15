<?php

namespace Modules\FixedAssets\Tests;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Modules\FixedAssets\Events\AssetDisposed;
use Modules\FixedAssets\Events\AssetImpaired;
use Modules\FixedAssets\Events\AssetRevalued;
use Modules\FixedAssets\Events\DepreciationPosted;
use Modules\FixedAssets\Tests\Concerns\BuildsFixedAssets;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// FA-14 to FA-17, FA-22: transfers and disposals through their flows
// (approval by the branch manager, never the requester), revaluations and
// impairments, with the amounts each raises to the ledger; disposal gain
// and loss, the surplus released, and the dates refused.
class MovementsTest extends TestCase
{
    use BuildsFixedAssets, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFixedAssets();
    }

    public function test_an_approved_transfer_moves_the_asset_and_a_rejected_one_does_not(): void
    {
        $asset = $this->active(['custodian_user_id' => $this->owner->id]);
        $transfer = $this->api('POST', "assets/{$asset['id']}/transfers", [
            'to_location_id' => $this->locationB->id, 'to_custodian_user_id' => $this->manager->id, 'to_cost_centre_id' => $this->operations->id,
            'transfer_date' => '2026-10-15', 'reason' => 'New branch',
        ], $this->accountant)->assertCreated()->json('data');

        $this->assertSame('pending', $transfer['status']);
        $this->assertMatchesRegularExpression('/^FAT-/', $transfer['number']);
        $this->api('POST', "assets/{$asset['id']}/transfers", ['to_location_id' => $this->locationB->id, 'transfer_date' => '2026-10-15', 'reason' => 'Again'])
            ->assertStatus(409)->assertJsonPath('code', 'transfer_pending');

        $this->approve($transfer['id']);
        $this->api('GET', "transfers/{$transfer['id']}")->assertOk()->assertJsonPath('data.status', 'applied');
        $moved = $this->api('GET', "assets/{$asset['id']}")->assertOk()->json('data');
        $this->assertSame([$this->locationB->id, $this->branchB->id, $this->manager->id, $this->operations->id],
            [$moved['location']['id'], $moved['branch']['id'], $moved['custodian']['id'], $moved['cost_centre']['id']]);
        $this->assertSame('transferred', $this->api('GET', "assets/{$asset['id']}/history")->json('data.0.kind'));

        $back = $this->api('POST', "assets/{$asset['id']}/transfers", ['to_location_id' => $this->locationA->id, 'transfer_date' => '2026-10-16', 'reason' => 'Back'])->assertCreated()->json('data');
        $this->reject($back['id'], $this->userWith('branch_manager', Scope::branch($this->branchB->id)));
        $this->api('GET', "transfers/{$back['id']}")->assertJsonPath('data.status', 'rejected');
        $this->api('GET', "assets/{$asset['id']}")->assertJsonPath('data.location.id', $this->locationB->id);
    }

    public function test_a_transfer_must_change_something_and_may_be_cancelled(): void
    {
        $asset = $this->active();
        $this->api('POST', "assets/{$asset['id']}/transfers", ['to_location_id' => $this->locationA->id, 'transfer_date' => '2026-10-15', 'reason' => 'Same'])
            ->assertStatus(422)->assertJsonPath('code', 'transfer_nothing');
        $transfer = $this->api('POST', "assets/{$asset['id']}/transfers", ['to_location_id' => $this->locationB->id, 'transfer_date' => '2026-10-15', 'reason' => 'Move'])->assertCreated()->json('data');
        $this->api('POST', "transfers/{$transfer['id']}/cancel", ['reason' => 'Not needed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->api('GET', "assets/{$asset['id']}")->assertJsonPath('data.location.id', $this->locationA->id);
    }

    public function test_a_sale_posts_the_catch_up_depreciation_and_the_gain(): void
    {
        Event::fake([AssetDisposed::class, DepreciationPosted::class]);
        // KES 1 200 000.00 from 2026-01-01; nothing run yet: the disposal charges 2026-01-01 to 2026-03-31 itself.
        $asset = $this->active();
        $disposal = $this->api('POST', "assets/{$asset['id']}/disposals", [
            'kind' => 'sale', 'disposal_date' => '2026-03-31', 'proceeds_minor' => 1000000, 'currency' => 'USD', 'party_id' => $this->buyer->id, 'reason' => 'Fleet renewal',
        ])->assertCreated()->json('data');
        $this->assertSame('pending', $disposal['status']);

        $this->approve($disposal['id']);
        $posted = $this->api('GET', "disposals/{$disposal['id']}")->assertOk()->json('data');
        $depreciation = intdiv(108000000 * 90 * 2 + 1826, 1826 * 2);
        $bookValue = 120000000 - $depreciation;
        // USD 10 000.00 at 130.
        $this->assertSame('posted', $posted['status']);
        $this->assertSame((string) $depreciation, $posted['depreciation']['amount_minor']);
        $this->assertSame('130000000', $posted['base_proceeds']['amount_minor']);
        $this->assertSame((string) $bookValue, $posted['book_value']['amount_minor']);
        $this->assertSame((string) (130000000 - $bookValue), $posted['gain']['amount_minor']);
        $this->api('GET', "assets/{$asset['id']}")->assertJsonPath('data.status', 'disposed')->assertJsonPath('data.disposed_on', '2026-03-31');

        $category = $this->vehicles->id;
        Event::assertDispatched(DepreciationPosted::class, fn (DepreciationPosted $e) => $e->amounts["depreciation.{$category}"] === (string) $depreciation);
        Event::assertDispatched(AssetDisposed::class, fn (AssetDisposed $e) => $e->amounts === [
            "cost.{$category}" => '120000000', "accumulated.{$category}" => (string) $depreciation, 'proceeds' => '130000000',
            'gain' => (string) (130000000 - $bookValue), 'loss' => '0', 'book_value' => (string) $bookValue, 'surplus' => '0',
        ] && $e->dimensions['party_id'] === $this->buyer->id);
    }

    public function test_a_scrapped_asset_is_a_loss_and_the_requester_never_approves(): void
    {
        $asset = $this->active(['acquired_on' => '2026-10-01']);
        $disposal = $this->api('POST', "assets/{$asset['id']}/disposals", ['kind' => 'scrap', 'disposal_date' => '2026-10-01', 'reason' => 'Crashed'], $this->manager)->assertCreated()->json('data');

        // APR-07: the requesting manager cannot approve their own disposal; another manager of the branch can.
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $disposal['id'])->sole()->id);
        $this->json('POST', "/api/v1/approvals/{$approval}/approve", [], $this->headersFor($this->manager))->assertStatus(403);
        $this->approve($disposal['id'], $this->userWith('branch_manager', Scope::branch($this->branchA->id)));

        $posted = $this->api('GET', "disposals/{$disposal['id']}")->json('data');
        // By days, the disposal day itself is depreciated: one of the life's 1 826 days.
        $day = intdiv(108000000 * 2 + 1826, 1826 * 2);
        $this->assertSame(['posted', (string) $day, (string) (120000000 - $day), (string) -(120000000 - $day)],
            [$posted['status'], $posted['depreciation']['amount_minor'], $posted['book_value']['amount_minor'], $posted['gain']['amount_minor']]);
    }

    public function test_a_disposal_dated_before_the_last_charge_is_refused(): void
    {
        $asset = $this->active();
        $this->api('POST', 'runs', ['company_id' => $this->acme->id, 'period' => '2026-01'])->assertCreated();

        $this->api('POST', "assets/{$asset['id']}/disposals", ['kind' => 'write_off', 'disposal_date' => '2026-01-15', 'reason' => 'Lost'])
            ->assertStatus(422)->assertJsonPath('code', 'period_depreciated');
        $this->api('POST', "assets/{$asset['id']}/disposals", ['kind' => 'sale', 'disposal_date' => '2026-02-15', 'reason' => 'Sold'])
            ->assertStatus(422)->assertJsonPath('code', 'proceeds_required');
    }

    public function test_revaluation_up_then_down_moves_the_surplus_and_profit_or_loss(): void
    {
        Event::fake([AssetRevalued::class]);
        $asset = $this->active(['category_id' => $this->land->id, 'cost_minor' => 500000000]);

        // Up by 100 000.00: all to the surplus.
        $this->api('POST', "assets/{$asset['id']}/revaluations", ['kind' => 'revaluation', 'revalued_on' => '2026-06-30', 'value_minor' => 600000000, 'reason' => 'Valuer'])
            ->assertCreated()->assertJsonPath('data.surplus_change.amount_minor', '100000000')->assertJsonPath('data.pnl_change.amount_minor', '0');
        // Down by 150 000.00: 100 000.00 from the surplus, 50 000.00 a loss.
        $this->api('POST', "assets/{$asset['id']}/revaluations", ['kind' => 'revaluation', 'revalued_on' => '2026-09-30', 'value_minor' => 450000000, 'reason' => 'Market fell'])
            ->assertCreated()->assertJsonPath('data.surplus_change.amount_minor', '-100000000')->assertJsonPath('data.pnl_change.amount_minor', '-50000000');
        // Up by 80 000.00: 50 000.00 reverses the earlier loss, 30 000.00 to the surplus.
        $this->api('POST', "assets/{$asset['id']}/revaluations", ['kind' => 'revaluation', 'revalued_on' => '2026-10-15', 'value_minor' => 530000000, 'reason' => 'Recovered'])
            ->assertCreated()->assertJsonPath('data.surplus_change.amount_minor', '30000000')->assertJsonPath('data.pnl_change.amount_minor', '50000000');

        $this->api('GET', "assets/{$asset['id']}")->assertJsonPath('data.book_value.amount_minor', '530000000')->assertJsonPath('data.surplus.amount_minor', '30000000');
        $category = $this->land->id;
        Event::assertDispatched(AssetRevalued::class, fn (AssetRevalued $e) => $e->amounts === [
            "accumulated.{$category}" => '0', "cost.{$category}" => '-150000000', 'surplus' => '-100000000', 'gain' => '0', 'loss' => '50000000',
        ]);
    }

    public function test_revaluation_eliminates_accumulated_depreciation_and_impairment_uses_the_surplus_first(): void
    {
        Event::fake([AssetImpaired::class]);
        $asset = $this->active();
        $run = $this->api('POST', 'runs', ['company_id' => $this->acme->id, 'period' => '2026-01'])->assertCreated()->json('data');
        $charged = (int) $run['lines'][0]['amount']['amount_minor'];

        $this->api('POST', "assets/{$asset['id']}/revaluations", ['kind' => 'revaluation', 'revalued_on' => '2026-01-31', 'value_minor' => 125000000, 'reason' => 'Valuer'])
            ->assertCreated()->assertJsonPath('data.surplus_change.amount_minor', (string) (125000000 - (120000000 - $charged)));
        $revalued = $this->api('GET', "assets/{$asset['id']}")->json('data');
        $this->assertSame(['125000000', '0', '125000000'], [$revalued['book_cost']['amount_minor'], $revalued['accumulated']['amount_minor'], $revalued['book_value']['amount_minor']]);

        $surplus = 125000000 - (120000000 - $charged);
        // Impaired to 100 000 000: the surplus first, the rest a loss.
        $this->api('POST', "assets/{$asset['id']}/revaluations", ['kind' => 'impairment', 'revalued_on' => '2026-02-01', 'value_minor' => 100000000, 'reason' => 'Damage'])
            ->assertCreated()->assertJsonPath('data.surplus_change.amount_minor', (string) -$surplus)->assertJsonPath('data.pnl_change.amount_minor', (string) -(25000000 - $surplus));
        $this->api('POST', "assets/{$asset['id']}/revaluations", ['kind' => 'impairment', 'revalued_on' => '2026-02-01', 'value_minor' => 100000000, 'reason' => 'Again'])
            ->assertStatus(422)->assertJsonPath('code', 'value_not_lower');
        $category = $this->vehicles->id;
        Event::assertDispatched(AssetImpaired::class, fn (AssetImpaired $e) => $e->amounts === [
            'impairment' => (string) (25000000 - $surplus), 'surplus' => (string) $surplus, "accumulated.{$category}" => '25000000',
        ]);
        // Before the last charge: refused.
        $this->api('POST', "assets/{$asset['id']}/revaluations", ['kind' => 'revaluation', 'revalued_on' => '2026-01-15', 'value_minor' => 1, 'reason' => 'Late'])
            ->assertStatus(422)->assertJsonPath('code', 'period_depreciated');
    }

    public function test_movement_permissions(): void
    {
        $asset = $this->active();
        $atB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $storekeeper = $this->userWith('storekeeper', Scope::location($this->locationA->id));

        $this->api('POST', "assets/{$asset['id']}/transfers", ['to_location_id' => $this->locationB->id, 'transfer_date' => '2026-10-15', 'reason' => 'x'], $atB)->assertNotFound();
        $this->api('POST', "assets/{$asset['id']}/disposals", ['kind' => 'scrap', 'disposal_date' => '2026-10-15', 'reason' => 'x'], $storekeeper)->assertNotFound();
        // A branch manager requests disposals but never revalues.
        $this->api('POST', "assets/{$asset['id']}/revaluations", ['kind' => 'impairment', 'revalued_on' => '2026-10-15', 'value_minor' => 1, 'reason' => 'x'], $this->manager)->assertForbidden();
        $this->api('GET', 'transfers', [], $atB)->assertOk()->assertJsonCount(0, 'data');
    }
}
