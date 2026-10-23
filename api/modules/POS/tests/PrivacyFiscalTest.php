<?php

namespace Modules\POS\Tests;

use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\POS\Fiscal\PosFiscalSource;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-08, POS-10 (owner decision 2026-10-22): anonymising a customer never
 * breaks the POS's fiscal documents. Each sale keeps its buyer snapshot, so
 * once none of the customer's fiscal documents is still being sent, the
 * party is fully anonymised; the sales keep the buyer as printed, recorded
 * as retained for a legal obligation. While one is still being sent, the
 * deletion waits (`fiscal_pending`). Review H1: the customer's report
 * holds their sales, never sales they did not make.
 */
class PrivacyFiscalTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private Party $customer;

    private string $saleId;

    private string $requestId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPos();
        $this->customer = $this->inTenant(fn () => Party::query()->create([
            'company_id' => $this->acme->id, 'kind' => 'organisation', 'name' => 'Duka Bora', 'legal_name' => 'Duka Bora Traders Ltd',
            'tax_id' => 'P051234567X', 'emails' => [['address' => 'orders@dukabora.example', 'label' => null]], 'roles' => ['customer'],
        ]));
        $this->ranges()->assertOk();
        $shift = $this->openShift();
        $sale = $this->saleBody($shift, 1, ['customer_id' => $this->customer->id]);
        $this->saleId = $sale['id'];
        $this->postJson('/api/v1/pos/sales', ['sales' => [$sale]], $this->tillHeaders())->assertOk()->assertJsonPath('results.0.status', 'stored');

        $admin = $this->userWith('admin', Scope::tenant());
        $this->requestId = $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'customer', 'subject_id' => $this->customer->id, 'kind' => 'deletion'], $this->headersFor())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/privacy/requests/{$this->requestId}/approve", [], $this->headersFor($admin))->assertOk();
    }

    private function submission(string $status): void
    {
        $this->inTenant(fn () => DB::table('fiscal_submissions')->insert([
            'id' => (string) Str::uuid7(), 'company_id' => $this->acme->id, 'country' => 'KE', 'driver' => 'fake', 'source' => 'pos',
            'document_type' => 'sale', 'document_id' => $this->saleId, 'invoice_no' => 1, 'payload' => '{}', 'status' => $status,
        ]));
    }

    public function test_a_deletion_waits_while_fiscal_documents_are_being_sent(): void
    {
        $this->submission('retrying');

        $this->postJson("/api/v1/privacy/requests/{$this->requestId}/execute", [], $this->headersFor())
            ->assertStatus(422)->assertJsonPath('code', 'fiscal_pending');

        $this->inTenant(fn () => $this->assertSame('Duka Bora', DB::table('parties')->where('id', $this->customer->id)->value('name')));
    }

    public function test_the_customer_is_fully_anonymised_and_the_sales_keep_the_buyer_as_printed(): void
    {
        $this->submission('accepted');

        $this->postJson("/api/v1/privacy/requests/{$this->requestId}/execute", [], $this->headersFor())->assertOk()
            ->assertJsonPath('data.result.retained_for_legal_obligation.0.table', 'pos_sales')
            ->assertJsonPath('data.result.retained_for_legal_obligation.0.columns', ['buyer_tax_id', 'buyer_name'])
            ->assertJsonPath('data.result.retained_for_legal_obligation.0.reason', 'fiscal_records');

        $this->inTenant(function () {
            $party = DB::table('parties')->where('id', $this->customer->id)->first();
            $this->assertNull($party->tax_id);
            $this->assertNull($party->legal_name);
            $this->assertNotSame('Duka Bora', $party->name);
            $this->assertStringNotContainsString('Duka Bora', $party->name);
            $this->assertSame('[]', $party->emails);
            $this->assertNotNull($party->archived_at);

            $sale = DB::table('pos_sales')->where('id', $this->saleId)->first();
            $this->assertSame(['P051234567X', 'Duka Bora'], [$sale->buyer_tax_id, $sale->buyer_name]);
            // A later refund or void still names the buyer the sale was made to.
            $this->assertEquals(['tin' => 'P051234567X', 'name' => 'Duka Bora'], app(PosFiscalSource::class)->document('sale', $this->saleId)->toArray()['customer']);
        });
    }

    public function test_a_customer_without_a_tax_id_has_nothing_retained(): void
    {
        $plain = $this->customer($this->acme->id);
        $shift = $this->inTenant(fn () => DB::table('pos_shifts')->where('status', 'open')->value('id'));
        $this->postJson('/api/v1/pos/sales', ['sales' => [$this->saleBody($shift, 2, ['customer_id' => $plain->id])]], $this->tillHeaders())->assertOk()->assertJsonPath('results.0.status', 'stored');
        $admin = $this->userWith('admin', Scope::tenant());
        $id = $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'customer', 'subject_id' => $plain->id, 'kind' => 'deletion'], $this->headersFor())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/privacy/requests/{$id}/approve", [], $this->headersFor($admin))->assertOk();

        $this->postJson("/api/v1/privacy/requests/{$id}/execute", [], $this->headersFor())->assertOk()
            ->assertJsonPath('data.result.retained_for_legal_obligation', []);
    }

    public function test_the_access_report_holds_the_customers_sales_and_names_what_is_retained(): void
    {
        $this->submission('accepted');
        $admin = $this->userWith('admin', Scope::tenant());
        $id = $this->postJson('/api/v1/privacy/requests', ['subject_type' => 'customer', 'subject_id' => $this->customer->id, 'kind' => 'access'], $this->headersFor())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/privacy/requests/{$id}/approve", [], $this->headersFor($admin))->assertOk();
        $this->postJson("/api/v1/privacy/requests/{$id}/execute", [], $this->headersFor())->assertOk();

        $report = $this->getJson("/api/v1/privacy/requests/{$id}/report", $this->headersFor())->assertOk()->json();

        $this->assertSame($this->saleId, $report['related']['pos_sales']['rows'][0]['id']);
        $this->assertSame(1, $report['related']['pos_sales']['count']);
        $this->assertSame(['table' => 'pos_sales', 'columns' => ['buyer_tax_id', 'buyer_name'], 'reason' => 'fiscal_records'], $report['retained_for_legal_obligation'][0]);
    }
}
