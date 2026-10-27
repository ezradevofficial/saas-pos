<?php

namespace Modules\VanSales\Tests;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Sync\DeviceScope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Contracts\StockWrittenOff;
use Modules\Inventory\Models\Movement;
use Modules\VanSales\Credit\Ledger;
use Modules\VanSales\Models\Count;
use Modules\VanSales\Models\CreditEntry;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\StockLine;
use Modules\VanSales\Models\Tender;
use Modules\VanSales\Models\VanSale;
use Modules\VanSales\Models\Visit;
use Modules\VanSales\Sync\Sources\RoutesSource;
use Modules\VanSales\Tests\Concerns\BuildsVanSales;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// The van sales security review (VAN-10, VAN-17, VAN-19 to VAN-24, RBAC-04
// to RBAC-06, APR-07, AUTH-07, NFR-08): sales and money that reach a counted
// day, the device naming its actor, who can void money, credit views by
// company, positions, contacts. Each test fails without its fix.
class SecurityReviewTest extends TestCase
{
    use BuildsVanSales, RefreshTenantDatabase;

    private string $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpVanSales();
        $this->run = $this->startRun();
        $this->loadVan('10', $this->run);
    }

    private function balancedCount(string $returned, array $tenders): array
    {
        $count = $this->countRecord($this->run, [['item_id' => $this->soap->id, 'returned_qty' => $returned, 'damaged_qty' => '0']], $tenders);
        $this->vsSync(['counts' => [$count]])->assertOk()->assertJsonPath('results.counts.0.reconciliation.status', 'balanced');

        return $count;
    }

    private function decide(string $countId, string $action, User $by)
    {
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $countId)->sole());

        return $this->postJson("/api/v1/approvals/{$approval->id}/{$action}", [], $this->headersFor($by));
    }

    private function collectionRecord(int $seq, string $amount, array $overrides = []): array
    {
        return array_replace([
            'id' => $this->id(), 'run_id' => $this->run, 'visit_id' => null, 'customer_id' => $this->shop->id, 'user_id' => $this->rep->id,
            'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => $amount,
            'receipt_seq' => $seq, 'receipt_number' => sprintf('R-V01-%06d', $seq), 'collected_at' => $this->at(30),
            'actor_proof' => $this->actorProof($this->rep->id, $this->vanDevice),
        ], $overrides);
    }

    /** A second person who both works a van and supervises the branch. */
    private function dualUser(): User
    {
        $user = $this->userWith('van_rep', Scope::location($this->vanLocation->id));
        $this->inTenant(fn () => $this->assign($user, $this->roles->get('route_supervisor'), Scope::branch($this->branchA->id)));

        return $user;
    }

    // -- 1. Sales and money that reach a counted day ------------------------------------------

    public function test_a_sale_after_the_count_is_flagged_adds_to_the_day_and_reopens_the_count(): void
    {
        $this->sellOnVan($this->vanSale($this->run, 1, null, [$this->pay('cash_kes', '225000')], '4'))->assertOk();
        $first = $this->balancedCount('6', [['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '225000']]);
        $this->inTenant(fn () => $this->assertSame('closed', Run::findOrFail($this->run)->status));

        $late = $this->vanSale($this->run, 2, null, [$this->pay('cash_kes', '56250')], '1');
        $this->sellOnVan($late)->assertOk()->assertJsonPath('results.0.status', 'stored');

        $this->inTenant(function () use ($late, $first) {
            $link = VanSale::query()->where('sale_id', $late['id'])->sole();
            $this->assertSame([['after_count'], 'pending', $this->run], [$link->flags, $link->review_status, $link->run_id]);
            $this->assertSame(5.0, (float) StockLine::query()->where('run_id', $this->run)->where('kind', 'sold')->sum('qty'));
            $this->assertSame(281250, (int) Tender::query()->where('run_id', $this->run)->sum('amount_minor'));
            $count = Count::findOrFail($first['id']);
            $this->assertSame('rejected', $count->status);
            $this->assertContains('reopened', $count->flags);
            $this->assertSame('counting', Run::findOrFail($this->run)->status);
        });

        // The recount is judged on all the day's figures.
        $second = $this->countRecord($this->run, [['item_id' => $this->soap->id, 'returned_qty' => '5', 'damaged_qty' => '0']], [['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '281250']]);
        $this->vsSync(['counts' => [$second]])->assertOk()->assertJsonPath('results.counts.0.reconciliation.status', 'balanced');
        $this->inTenant(fn () => $this->assertSame([2, 'closed'], [Count::findOrFail($second['id'])->attempt, Run::findOrFail($this->run)->status]));

        // The old count's cash was short of the late sale: counting the old way would have hidden it.
        $stale = $this->countRecord($this->run, [['item_id' => $this->soap->id, 'returned_qty' => '6', 'damaged_qty' => '0']], [['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '225000']]);
        $this->vsSync(['counts' => [$stale]])->assertStatus(422)->assertJsonPath('results.counts.0.code', 'run_closed');
    }

    public function test_a_waiting_count_is_cancelled_when_a_sale_reaches_the_day(): void
    {
        $this->sellOnVan($this->vanSale($this->run, 1, null, [$this->pay('cash_kes', '225000')], '4'))->assertOk();
        $waiting = $this->countRecord($this->run, [['item_id' => $this->soap->id, 'returned_qty' => '5', 'damaged_qty' => '0']], [['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '225000']]);
        $this->vsSync(['counts' => [$waiting]])->assertOk()->assertJsonPath('results.counts.0.reconciliation.status', 'pending');

        $this->sellOnVan($this->vanSale($this->run, 2, null, [$this->pay('cash_kes', '56250')], '1'))->assertOk();

        $this->inTenant(fn () => $this->assertSame('rejected', Count::findOrFail($waiting['id'])->status));
        $this->decide($waiting['id'], 'approve', $this->supervisor)->assertUnprocessable();
    }

    public function test_a_credit_sale_after_the_count_is_still_ledgered_and_judged(): void
    {
        $this->balancedCount('10', []);

        $late = $this->vanSale($this->run, 1, $this->shop, [$this->pay('credit', '1518750')], '27');
        $this->sellOnVan($late)->assertOk();

        $this->inTenant(function () use ($late) {
            $link = VanSale::query()->where('sale_id', $late['id'])->sole();
            $this->assertSame(['after_count', 'over_limit'], $link->flags);
            $this->assertSame(['KES' => 1518750], app(Ledger::class)->balances($this->shop->id));
        });
    }

    public function test_a_collection_after_the_count_reopens_it(): void
    {
        $this->sellOnVan($this->vanSale($this->run, 1, $this->shop, [$this->pay('credit', '112500')]))->assertOk();
        $first = $this->balancedCount('8', []);

        $this->vsSync(['collections' => [$this->collectionRecord(2, '50000')]])->assertOk()->assertJsonPath('results.collections.0.flags', ['received_after_count']);

        $this->inTenant(function () use ($first) {
            $this->assertSame('rejected', Count::findOrFail($first['id'])->status);
            $this->assertSame('counting', Run::findOrFail($this->run)->status);
            $this->assertSame(50000, (int) Tender::query()->where('run_id', $this->run)->where('source_type', 'collection')->sum('amount_minor'));
        });
    }

    // -- 2. Returns without Inventory ------------------------------------------------------------

    public function test_without_inventory_returns_above_what_the_day_allows_are_flagged_and_decided(): void
    {
        $this->sellOnVan($this->vanSale($this->run, 1, null, [$this->pay('cash_kes', '225000')], '4'))->assertOk();
        $count = $this->countRecord($this->run, [['item_id' => $this->soap->id, 'returned_qty' => '8', 'damaged_qty' => '0']], [['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '225000']]);

        $this->vsSync(['counts' => [$count]])->assertOk()->assertJsonPath('results.counts.0.reconciliation.status', 'pending');

        $this->inTenant(function () use ($count) {
            $saved = Count::findOrFail($count['id']);
            $this->assertContains('returns_exceed', $saved->flags);
            $this->assertSame(-2.0, (float) $saved->lines()->sole()->variance_qty);
        });
    }

    // -- 3. The device names the actor -------------------------------------------------------------

    public function test_a_collection_needs_the_proven_actor(): void
    {
        $unproven = $this->collectionRecord(1, '1000', ['actor_proof' => null]);
        $other = $this->collectionRecord(2, '1000', ['actor_proof' => $this->actorProof($this->supervisor->id, $this->vanDevice)]);

        $this->vsSync(['collections' => [$unproven]])->assertStatus(422)->assertJsonPath('results.collections.0.code', 'actor_unverified');
        // A proof of somebody else does not prove the rep.
        $this->vsSync(['collections' => [$other]])->assertStatus(422)->assertJsonPath('results.collections.0.code', 'actor_unverified');
        $this->vsSync(['collections' => [$this->collectionRecord(3, '1000')]])->assertOk()->assertJsonPath('results.collections.0.status', 'stored');
    }

    public function test_a_count_comes_from_the_runs_rep_or_carries_the_proof(): void
    {
        $second = $this->dualUser();
        $lines = [['item_id' => $this->soap->id, 'returned_qty' => '10', 'damaged_qty' => '0']];

        $this->vsSync(['counts' => [$this->countRecord($this->run, $lines, [], ['user_id' => $second->id])]])->assertStatus(422)->assertJsonPath('results.counts.0.code', 'actor_unverified');
        $proven = $this->countRecord($this->run, $lines, [], ['user_id' => $second->id, 'actor_proof' => $this->actorProof($second->id, $this->vanDevice)]);
        $this->vsSync(['counts' => [$proven]])->assertOk()->assertJsonPath('results.counts.0.status', 'stored');
    }

    public function test_a_load_confirmation_needs_the_proven_actor(): void
    {
        $load = $this->api('POST', 'van-sales/loads', ['van_id' => $this->van->id, 'from_location_id' => $this->locationA->id, 'lines' => [['item_id' => $this->soap->id, 'qty' => '3']]], $this->supervisor)->assertCreated()->json('data');
        $body = ['user_id' => $this->rep->id, 'lines' => [['id' => $load['lines'][0]['id'], 'qty_received' => '3']]];

        $this->postJson("/api/v1/van-sales/device/loads/{$load['id']}/confirm", $body, $this->tillHeaders($this->vanToken))->assertStatus(422)->assertJsonPath('code', 'actor_unverified');
        $this->postJson("/api/v1/van-sales/device/loads/{$load['id']}/confirm", [...$body, 'actor_proof' => $this->actorProof($this->rep->id, $this->vanDevice)], $this->tillHeaders($this->vanToken))->assertOk();
    }

    public function test_neither_the_submitter_nor_the_runs_rep_decides_the_reconciliation(): void
    {
        $second = $this->dualUser();
        // The rep also supervises; a colleague (with proof) submits the count for the day.
        $this->inTenant(fn () => $this->assign($this->rep, $this->roles->get('route_supervisor'), Scope::branch($this->branchA->id)));
        $count = $this->countRecord($this->run, [['item_id' => $this->soap->id, 'returned_qty' => '9', 'damaged_qty' => '0']], [], [
            'user_id' => $second->id, 'actor_proof' => $this->actorProof($second->id, $this->vanDevice),
        ]);
        $this->vsSync(['counts' => [$count]])->assertOk()->assertJsonPath('results.counts.0.reconciliation.status', 'pending');

        $this->assertContains($this->decide($count['id'], 'approve', $second)->getStatusCode(), [403, 404]);
        $this->assertContains($this->decide($count['id'], 'approve', $this->rep)->getStatusCode(), [403, 404]);
        $this->inTenant(fn () => $this->assertSame('pending', Count::findOrFail($count['id'])->status));
        $this->decide($count['id'], 'approve', $this->supervisor)->assertOk();
        $this->inTenant(fn () => $this->assertSame('approved', Count::findOrFail($count['id'])->status));
    }

    // -- 5. Voiding money -----------------------------------------------------------------------

    public function test_a_collection_is_not_voided_by_its_collector_or_above_the_limit(): void
    {
        $both = $this->dualUser();
        $own = $this->collectionRecord(1, '5000', ['user_id' => $both->id, 'actor_proof' => $this->actorProof($both->id, $this->vanDevice)]);
        $big = $this->collectionRecord(2, '2000000');
        $this->vsSync(['collections' => [$own, $big]])->assertOk();

        $this->api('POST', "van-sales/collections/{$own['id']}/void", ['reason' => 'Mistake'], $both)->assertForbidden()->assertJsonPath('code', 'void_own_collection');
        // KES 20,000 is above the supervisor's KES 10,000 (RBAC-06); the Owner has no limit.
        $this->api('POST', "van-sales/collections/{$big['id']}/void", ['reason' => 'Mistake'], $this->supervisor)->assertForbidden()->assertJsonPath('code', 'void_limit_exceeded');
        $this->api('POST', "van-sales/collections/{$big['id']}/void", ['reason' => 'Mistake'], $this->owner)->assertOk()->assertJsonPath('data.status', 'voided');
        $this->api('POST', "van-sales/collections/{$own['id']}/void", ['reason' => 'Mistake'], $this->supervisor)->assertOk();
    }

    public function test_a_void_after_the_count_reopens_it(): void
    {
        $record = $this->collectionRecord(1, '50000');
        $this->vsSync(['collections' => [$record]])->assertOk();
        $count = $this->balancedCount('10', [['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '50000']]);

        $this->api('POST', "van-sales/collections/{$record['id']}/void", ['reason' => 'Wrong customer'], $this->supervisor)->assertOk();

        $this->inTenant(function () use ($count) {
            $this->assertSame('rejected', Count::findOrFail($count['id'])->status);
            $this->assertSame('counting', Run::findOrFail($this->run)->status);
        });
    }

    // -- 6. Collections need a customer of the company ------------------------------------------------

    public function test_a_collection_needs_a_customer_of_the_runs_company(): void
    {
        [$supplier, $foreign] = $this->inTenant(function () {
            $other = $this->company('Other Co');

            return [
                Party::create(['kind' => 'organisation', 'name' => 'Supplier only', 'roles' => ['supplier'], 'company_id' => $this->acme->id]),
                Party::create(['kind' => 'organisation', 'name' => 'Foreign customer', 'roles' => ['customer'], 'company_id' => $other->id]),
            ];
        });

        $this->vsSync(['collections' => [$this->collectionRecord(1, '1000', ['customer_id' => $supplier->id])]])->assertStatus(422)->assertJsonPath('results.collections.0.code', 'customer_unknown');
        $this->vsSync(['collections' => [$this->collectionRecord(2, '1000', ['customer_id' => $foreign->id])]])->assertStatus(422)->assertJsonPath('results.collections.0.code', 'customer_unknown');
        $this->vsSync(['visits' => [$this->visitRecord($this->run, $foreign)]])->assertStatus(422)->assertJsonPath('results.visits.0.code', 'customer_unknown');
        $this->vsSync(['collections' => [$this->collectionRecord(3, '1000')]])->assertOk();
    }

    // -- 7. Positions go on schedule -------------------------------------------------------------------

    public function test_positions_are_purged_even_when_the_module_is_off(): void
    {
        $this->api('PUT', "van-sales/companies/{$this->acme->id}/settings", ['gps_checkin' => 'optional', 'gps_retention_days' => 7], $this->owner)->assertOk();
        $visit = $this->visitRecord($this->run, $this->shop, ['check_in' => ['lat' => -1.2864, 'lng' => 36.8172, 'accuracy_m' => 9, 'at' => $this->at(100)]]);
        $this->vsSync(['visits' => [$visit]])->assertOk();
        $this->inTenant(function () use ($visit) {
            Visit::query()->whereKey($visit['id'])->update(['checkin_at' => now()->subDays(9)]);
            app(ModuleRegistry::class)->deactivate('van_sales');
        });

        Artisan::call('van-sales:purge-positions');

        $this->inTenant(fn () => $this->assertNull(Visit::findOrFail($visit['id'])->checkin_lat));
    }

    // -- 8. Credit views by company --------------------------------------------------------------------

    public function test_credit_balances_and_entries_are_those_of_the_readers_companies(): void
    {
        $this->sellOnVan($this->vanSale($this->run, 1, $this->shop, [$this->pay('credit', '112500')]))->assertOk();
        $this->inTenant(function () {
            $other = $this->company('Other Co');
            CreditEntry::create(['company_id' => $other->id, 'customer_id' => $this->shop->id, 'kind' => 'sale', 'source_id' => $this->id(), 'currency' => 'KES', 'amount_minor' => '500000', 'occurred_at' => now()]);
        });

        $detail = $this->api('GET', "van-sales/accounts/{$this->shop->id}", [], $this->supervisor)->assertOk();
        $this->assertSame([['currency' => 'KES', 'amount_minor' => '112500']], $detail->json('data.balances'));
        $this->assertCount(1, $detail->json('data.entries'));
        $this->assertSame('112500', $this->api('GET', 'van-sales/accounts', [], $this->supervisor)->assertOk()->json('data.0.balances.0.amount_minor'));
        // The Owner sees both companies.
        $this->assertSame('612500', $this->api('GET', "van-sales/accounts/{$this->shop->id}", [], $this->owner)->json('data.balances.0.amount_minor'));
    }

    // -- 9. Loads are requested with the right at the warehouse -----------------------------------------

    public function test_a_load_is_requested_only_from_a_warehouse_the_requester_reaches(): void
    {
        $locationOnlyHere = $this->userWith('route_supervisor', Scope::location($this->vanLocation->id));
        $line = [['item_id' => $this->soap->id, 'qty' => '1']];

        $this->api('POST', 'van-sales/loads', ['van_id' => $this->van->id, 'from_location_id' => $this->locationA->id, 'lines' => $line], $locationOnlyHere)->assertForbidden();
        $this->api('POST', 'van-sales/loads', ['van_id' => $this->van->id, 'from_location_id' => $this->locationA->id, 'lines' => $line], $this->supervisor)->assertCreated();
    }

    // -- 10. Contacts follow the field rules ---------------------------------------------------------------

    public function test_customer_phones_reach_the_device_only_if_the_reps_role_may_see_them(): void
    {
        $this->inTenant(fn () => $this->shop->forceFill(['phones' => [['number' => '+254700111222', 'label' => 'mobile']]])->save());
        $stop = fn () => $this->inTenant(fn () => collect((new RoutesSource)->rows(DeviceScope::of($this->vanDevice))[0]['stops'])->firstWhere('customer_name', 'Corner shop'));

        $this->assertSame('+254700111222', $stop()['phone']);

        $this->inTenant(fn () => FieldRule::create(['role_id' => $this->roles->get('van_rep')->id, 'resource' => 'party', 'field' => 'phones', 'mode' => FieldRule::HIDDEN]));
        $this->assertNull($stop()['phone']);
    }

    // -- 11. Offline credit is read from the upload --------------------------------------------------------------

    public function test_offline_credit_is_worked_out_from_the_upload_not_the_devices_word(): void
    {
        $late = $this->vanSale($this->run, 1, $this->shop, [$this->pay('credit', '112500')], '2', ['credit' => ['limit_minor' => '1000000', 'offline' => false]], ['sold_at' => now()->subHours(6)->toIso8601String()]);
        $fresh = $this->vanSale($this->run, 2, $this->shop, [$this->pay('credit', '112500')], '2', ['credit' => ['limit_minor' => '1000000', 'offline' => true]]);
        $noLimit = $this->vanSale($this->run, 3, $this->shop, [$this->pay('credit', '112500')], '2', ['credit' => []]);

        $this->sellOnVan($late, $fresh, $noLimit)->assertOk();

        $this->inTenant(function () use ($late, $fresh, $noLimit) {
            $flags = fn (array $sale) => VanSale::query()->where('sale_id', $sale['id'])->sole()->flags;
            $this->assertSame(['credit_offline'], $flags($late));
            $this->assertSame([], $flags($fresh));
            $this->assertSame(['limit_changed'], $flags($noLimit));
        });
    }

    // -- 12. Write-off sources are allow-listed ------------------------------------------------------------------

    public function test_a_write_off_names_only_a_known_source_in_the_stock_ledger(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));
        Log::spy();
        $event = fn (string $source) => new StockWrittenOff(
            $this->inTenant(fn () => $this->owner->tenant_id), $this->id(), $this->acme->id, $this->vanLocation->id, now()->toIso8601String(), 'x',
            [['line_id' => $this->id(), 'item_id' => $this->soap->id, 'qty' => '1']], null, $source,
        );

        event($event('pos.sale'));
        $this->inTenant(fn () => $this->assertSame(0, Movement::query()->where('source_type', 'pos.sale')->count()));

        event($event('van_sales.write_off'));
        $this->inTenant(fn () => $this->assertSame(1, Movement::query()->where('source_type', 'van_sales.write_off')->count()));
    }
}
