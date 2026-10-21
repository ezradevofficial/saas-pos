<?php

namespace Modules\POS\Tests;

use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-08, POS-10 (review M3): anonymising a customer never breaks the POS's
 * fiscal documents. While any of their fiscal documents is still being
 * sent, the deletion waits; afterwards the party keeps its tax_id and legal
 * name (the buyer of later refunds and voids), recorded as retained for a
 * legal obligation, and everything else is anonymised. Review H1: the
 * customer's report holds their sales, never sales they did not make.
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

    public function test_the_tax_identity_is_kept_for_fiscal_records_and_the_rest_anonymised(): void
    {
        $this->submission('accepted');

        $this->postJson("/api/v1/privacy/requests/{$this->requestId}/execute", [], $this->headersFor())->assertOk()
            ->assertJsonPath('data.result.retained_for_legal_obligation.0.table', 'parties')
            ->assertJsonPath('data.result.retained_for_legal_obligation.0.reason', 'fiscal_records');

        $this->inTenant(function () {
            $party = DB::table('parties')->where('id', $this->customer->id)->first();
            $this->assertSame('P051234567X', $party->tax_id);
            $this->assertSame('Duka Bora Traders Ltd', $party->legal_name);
            // The name later fiscal documents print is the legal name.
            $this->assertSame('Duka Bora Traders Ltd', $party->name);
            $this->assertSame('[]', $party->emails);
            $this->assertNotNull($party->archived_at);
        });
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
        $this->assertSame('fiscal_records', $report['retained_for_legal_obligation'][0]['reason']);
    }
}
