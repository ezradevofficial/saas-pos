<?php

namespace Modules\VanSales\Tests;

use Modules\VanSales\Credit\Ledger;
use Modules\VanSales\Models\Collection;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\Tender;
use Modules\VanSales\Tests\Concerns\BuildsVanSales;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// VAN-13, VAN-14, VAN-17: collections of balances on the route: numbered
// from the device's range, idempotent, flagged above the balance, voided by
// a supervisor with the balance and the run's cash coming back.
class CollectionsTest extends TestCase
{
    use BuildsVanSales, RefreshTenantDatabase;

    private string $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpVanSales();
        $this->run = $this->startRun();
        $this->sellOnVan($this->vanSale($this->run, 1, $this->shop, [$this->pay('credit', '112500')]))->assertOk();
    }

    private function collection(int $seq, string $amount = '50000', string $method = 'cash_kes', array $overrides = []): array
    {
        return array_replace([
            'id' => $this->id(), 'run_id' => $this->run, 'visit_id' => null, 'customer_id' => $this->shop->id, 'user_id' => $this->rep->id,
            'payment_method_id' => $this->methods[$method]->id, 'currency' => 'KES', 'amount_minor' => $amount, 'provider_reference' => null,
            'receipt_seq' => $seq, 'receipt_number' => sprintf('R-V01-%06d', $seq), 'collected_at' => $this->at(30),
            'actor_proof' => $this->actorProof($this->rep->id, $this->vanDevice),
        ], $overrides);
    }

    private function balance(): array
    {
        return $this->inTenant(fn () => app(Ledger::class)->balances($this->shop->id));
    }

    public function test_a_collection_reduces_the_balance_and_adds_to_the_expected_cash(): void
    {
        $record = $this->collection(2);

        $this->vsSync(['collections' => [$record]])->assertOk()->assertJsonPath('results.collections.0.status', 'stored')
            ->assertJsonPath('results.collections.0.receipt_number', 'R-V01-000002')->assertJsonPath('results.collections.0.flags', []);
        $this->vsSync(['collections' => [$record]])->assertOk()->assertJsonPath('results.collections.0.status', 'stored');

        $this->assertSame(['KES' => 62500], $this->balance());
        $this->inTenant(function () {
            $this->assertSame(1, Collection::query()->count());
            $this->assertSame(50000, (int) Tender::query()->where('run_id', $this->run)->where('source_type', 'collection')->sum('amount_minor'));
        });
    }

    public function test_m_pesa_collections_count_as_mobile_money(): void
    {
        $this->vsSync(['collections' => [$this->collection(2, '20000', 'mpesa', ['provider_reference' => 'QWE123'])]])->assertOk();

        $this->inTenant(fn () => $this->assertSame('mobile_money', Tender::query()->where('source_type', 'collection')->sole()->kind));
    }

    public function test_another_content_for_the_same_id_is_refused_and_a_foreign_number_is_too(): void
    {
        $record = $this->collection(2);
        $this->vsSync(['collections' => [$record]])->assertOk();

        $this->vsSync(['collections' => [[...$record, 'amount_minor' => '60000']]])->assertStatus(422)->assertJsonPath('results.collections.0.code', 'payload_mismatch');
        $this->vsSync(['collections' => [$this->collection(3, '1000', 'cash_kes', ['receipt_number' => 'R-V01-009999'])]])->assertStatus(422)
            ->assertJsonPath('results.collections.0.code', 'receipt_number_mismatch');
        $this->vsSync(['collections' => [$this->collection(9999999, '1000', 'cash_kes', ['receipt_number' => 'R-V01-9999999'])]])->assertStatus(422)
            ->assertJsonPath('results.collections.0.code', 'receipt_range_unknown');

        $this->assertSame(['KES' => 62500], $this->balance());
    }

    public function test_more_than_the_balance_is_stored_and_flagged_and_a_closed_run_flags_lateness(): void
    {
        $this->vsSync(['collections' => [$this->collection(2, '200000')]])->assertOk()->assertJsonPath('results.collections.0.flags', ['exceeds_balance']);

        $this->inTenant(fn () => Run::query()->whereKey($this->run)->update(['status' => 'counting']));
        $this->vsSync(['collections' => [$this->collection(3, '1000')]])->assertOk()->assertJsonPath('results.collections.0.flags', ['received_after_count', 'exceeds_balance']);
    }

    public function test_a_collection_with_a_credit_method_or_a_bad_amount_is_refused(): void
    {
        $this->vsSync(['collections' => [$this->collection(2, '1000', 'credit')]])->assertStatus(422)->assertJsonPath('results.collections.0.code', 'collection_invalid');
        $this->vsSync(['collections' => [$this->collection(2, '0')]])->assertStatus(422)->assertJsonPath('results.collections.0.code', 'collection_invalid');
    }

    public function test_a_supervisor_voids_a_collection_and_the_balance_returns(): void
    {
        $record = $this->collection(2);
        $this->vsSync(['collections' => [$record]])->assertOk();

        $this->api('POST', "van-sales/collections/{$record['id']}/void", ['reason' => 'Wrong customer'], $this->rep)->assertForbidden();
        $this->api('POST', "van-sales/collections/{$record['id']}/void", [], $this->supervisor)->assertUnprocessable();
        $this->api('POST', "van-sales/collections/{$record['id']}/void", ['reason' => 'Wrong customer'], $this->supervisor)
            ->assertOk()->assertJsonPath('data.status', 'voided')->assertJsonPath('data.void_reason', 'Wrong customer');
        $this->api('POST', "van-sales/collections/{$record['id']}/void", ['reason' => 'Again'], $this->supervisor)->assertConflict();

        $this->assertSame(['KES' => 112500], $this->balance());
        $this->inTenant(fn () => $this->assertSame(0, (int) Tender::query()->where('run_id', $this->run)->whereIn('source_type', ['collection', 'collection_void'])->sum('amount_minor')));
    }

    public function test_collections_are_listed_for_those_who_may_and_not_across_tenants(): void
    {
        $this->vsSync(['collections' => [$this->collection(2)]])->assertOk();

        $this->api('GET', 'van-sales/collections', [], $this->supervisor)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_name', 'Corner shop')->assertJsonPath('data.0.amount_minor', '50000');

        $other = $this->otherVanTenant();
        $this->getJson('/api/v1/van-sales/collections', $this->headersFor($other['user']))->assertOk()->assertJsonCount(0, 'data');
        $id = $this->inTenant(fn () => Collection::query()->value('id'));
        $this->postJson("/api/v1/van-sales/collections/{$id}/void", ['reason' => 'x'], $this->headersFor($other['user']))->assertNotFound();
    }
}
