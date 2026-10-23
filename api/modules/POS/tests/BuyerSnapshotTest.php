<?php

namespace Modules\POS\Tests;

use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\POS\Fiscal\BuyerSnapshotBackfill;
use Modules\POS\Fiscal\PosFiscalSource;
use Modules\POS\Models\Sale;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * POS-08, POS-10, NFR-08 (owner decision 2026-10-22): a sale to a customer
 * with a tax id keeps a buyer snapshot (`buyer_tax_id`, `buyer_name`). The
 * till sends it as it sold (device wins for completed sales); a till that
 * sends none gets the customer's at upload. The fiscal documents of the
 * sale, its refunds and its void read the snapshot, never the live party,
 * so a later change or anonymisation of the party never changes a buyer.
 * Uploads stay idempotent: the same sale again is answered as stored.
 * Sales stored before the snapshot are backfilled in chunks.
 */
class BuyerSnapshotTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private string $shift;

    private Party $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPos();
        $this->ranges()->assertOk();
        $this->shift = $this->openShift();
        $this->buyer = $this->inTenant(fn () => Party::query()->create([
            'company_id' => $this->acme->id, 'kind' => 'organisation', 'name' => 'Duka Bora', 'legal_name' => 'Duka Bora Traders Ltd',
            'tax_id' => 'P051234567X', 'roles' => ['customer'],
        ]));
    }

    private function stored(string $id): Sale
    {
        return $this->inTenant(fn () => Sale::query()->findOrFail($id));
    }

    /** @return array<string, mixed>|null the buyer a fiscal document of $type names */
    private function fiscalBuyer(string $type, string $id): ?array
    {
        return $this->inTenant(fn () => app(PosFiscalSource::class)->document($type, $id)->toArray()['customer'] ?? null);
    }

    public function test_the_tills_snapshot_is_stored_as_sold(): void
    {
        // Offline, the till knew an older PIN: the sale keeps what it printed.
        $sale = $this->saleBody($this->shift, 1, ['customer_id' => $this->buyer->id, 'buyer_tax_id' => 'P051234567A', 'buyer_name' => 'Duka Bora']);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');

        $stored = $this->stored($sale['id']);
        $this->assertSame(['P051234567A', 'Duka Bora'], [$stored->buyer_tax_id, $stored->buyer_name]);

        // The same sale again (a lost answer) is answered as stored; nothing changes.
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->assertSame(1, $this->inTenant(fn () => Sale::query()->count()));
        // Other content under the same id is still refused.
        $this->upload([[...$sale, 'buyer_tax_id' => 'P000000000Z']])->assertUnprocessable()->assertJsonPath('code', 'upload_rejected')->assertJsonPath('results.0.status', 'rejected')->assertJsonPath('results.0.error.code', 'payload_mismatch');
    }

    public function test_a_till_that_sends_no_snapshot_gets_the_customers_tax_identity(): void
    {
        $sale = $this->saleBody($this->shift, 1, ['customer_id' => $this->buyer->id]);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');

        $stored = $this->stored($sale['id']);
        $this->assertSame(['P051234567X', 'Duka Bora'], [$stored->buyer_tax_id, $stored->buyer_name]);

        // Explicit nulls (an older record resent) hash as the keys left out: still the same sale.
        $this->upload([[...$sale, 'buyer_tax_id' => null, 'buyer_name' => null]])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->assertSame(1, $this->inTenant(fn () => Sale::query()->count()));
    }

    public function test_no_snapshot_without_a_customer_tax_id_and_none_without_a_customer(): void
    {
        $plain = $this->customer($this->acme->id);
        $first = $this->saleBody($this->shift, 1, ['customer_id' => $plain->id]);
        $second = $this->saleBody($this->shift, 2);
        $this->upload([$first, $second])->assertOk()->assertJsonPath('results.0.status', 'stored')->assertJsonPath('results.1.status', 'stored');

        foreach ([$first, $second] as $sale) {
            $stored = $this->stored($sale['id']);
            $this->assertSame([null, null], [$stored->buyer_tax_id, $stored->buyer_name]);
        }

        // A snapshot needs a customer, and both fields go together.
        $this->upload([$this->saleBody($this->shift, 3, ['buyer_tax_id' => 'P051234567X', 'buyer_name' => 'Duka Bora'])])
            ->assertUnprocessable()->assertJsonValidationErrors('sales.0.customer_id');
        $this->upload([$this->saleBody($this->shift, 3, ['customer_id' => $this->buyer->id, 'buyer_tax_id' => 'P051234567X'])])
            ->assertUnprocessable()->assertJsonValidationErrors('sales.0.buyer_name');
    }

    public function test_fiscal_documents_read_the_snapshot_not_the_live_party(): void
    {
        $sale = $this->saleBody($this->shift, 1, ['customer_id' => $this->buyer->id]);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $void = $this->id();
        $this->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $void, 'sale_id' => $sale['id'], 'voided_by_id' => $this->owner->id, 'voided_at' => now()->toIso8601String(),
            'reason' => 'Wrong items', 'override' => null, 'actor_proof' => $this->actorProof($this->owner->id),
        ]]], $this->tillHeaders())->assertOk();

        // The party changes (or is anonymised) after the sale.
        $this->inTenant(fn () => DB::table('parties')->where('id', $this->buyer->id)->update(['name' => 'Anonymised 1A2B3C4D', 'legal_name' => null, 'tax_id' => null]));

        $this->assertEquals(['tin' => 'P051234567X', 'name' => 'Duka Bora'], $this->fiscalBuyer('sale', $sale['id']));
        $this->assertEquals(['tin' => 'P051234567X', 'name' => 'Duka Bora'], $this->fiscalBuyer('void', $void));

        // A sale without a snapshot names no buyer, whatever its party says.
        $plain = $this->customer($this->acme->id);
        $other = $this->saleBody($this->shift, 2, ['customer_id' => $plain->id]);
        $this->upload([$other])->assertOk();
        $this->inTenant(fn () => DB::table('parties')->where('id', $plain->id)->update(['tax_id' => 'P099999999Z']));
        $this->assertNull($this->fiscalBuyer('sale', $other['id']));
    }

    public function test_the_backfill_gives_earlier_sales_their_customers_tax_identity_in_chunks(): void
    {
        $plain = $this->customer($this->acme->id);
        $sales = [
            $this->saleBody($this->shift, 1, ['customer_id' => $this->buyer->id]),
            $this->saleBody($this->shift, 2, ['customer_id' => $plain->id]),
            $this->saleBody($this->shift, 3, ['customer_id' => $this->buyer->id]),
            $this->saleBody($this->shift, 4),
        ];
        $this->upload($sales)->assertOk();
        // As stored before the snapshot existed.
        $this->inTenant(fn () => DB::table('pos_sales')->update(['buyer_tax_id' => null, 'buyer_name' => null]));

        $this->assertSame(2, $this->inTenant(fn () => BuyerSnapshotBackfill::run(DB::connection(TenantContext::CONNECTION), chunk: 1)));

        $snapshots = $this->inTenant(fn () => DB::table('pos_sales')->orderBy('receipt_seq')->get(['buyer_tax_id', 'buyer_name'])->map(fn ($r) => [$r->buyer_tax_id, $r->buyer_name])->all());
        $this->assertSame([['P051234567X', 'Duka Bora'], [null, null], ['P051234567X', 'Duka Bora'], [null, null]], $snapshots);

        // Rerunnable: nothing left to do, and a snapshot already there is never rewritten.
        $this->inTenant(fn () => DB::table('parties')->where('id', $this->buyer->id)->update(['tax_id' => 'P099999999Z']));
        $this->assertSame(0, $this->inTenant(fn () => BuyerSnapshotBackfill::run(DB::connection(TenantContext::CONNECTION))));
        $this->assertSame('P051234567X', $this->stored($sales[0]['id'])->buyer_tax_id);
    }
}
