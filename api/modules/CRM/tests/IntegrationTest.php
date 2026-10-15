<?php

namespace Modules\CRM\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\DocumentLine;
use Modules\Accounting\Models\PostingRule;
use Modules\CRM\Models\Quote;
use Modules\CRM\Tests\Concerns\BuildsCrm;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CRM-24, CRM-25, CRM-30: an accepted quote becomes an Accounting draft
// invoice only while Accounting is active (its listener, in the
// converting transaction); the customer 360 view shows each module's part
// only while it is active and the reader may see it; invoice targets read
// Accounting's contract.
class IntegrationTest extends TestCase
{
    use BuildsCrm, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrm();
    }

    private function withAccounting(): void
    {
        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('accounting');
            app(ModuleRegistry::class)->activate('accounting_pro');
            app(Charts::class)->fromTemplate($this->acme);
        });
    }

    public function test_with_accounting_off_a_quote_is_not_converted(): void
    {
        $quote = $this->acceptedQuote();
        $this->api('POST', "crm/quotes/{$quote['id']}/convert", ['target' => 'invoice'])->assertStatus(409)->assertJsonPath('code', 'target_inactive');
        $this->api('POST', "crm/quotes/{$quote['id']}/convert", ['target' => 'pos_order'])->assertStatus(409)->assertJsonPath('code', 'target_unavailable');
        $this->assertSame('accepted', $this->inTenant(fn () => Quote::query()->findOrFail($quote['id'])->status));
    }

    public function test_with_accounting_on_an_accepted_quote_becomes_one_draft_invoice_with_the_same_total(): void
    {
        $this->withAccounting();
        $this->assertNotNull($this->inTenant(fn () => PostingRule::query()->where('event_type', 'crm.quote_invoice')->first()), 'the KE chart seeds the quote invoice rule');
        $quote = $this->acceptedQuote(lines: [
            ['item_id' => $this->widget->id, 'qty' => '3', 'discount_percent' => '7.5'],
            ['description' => 'Installation', 'qty' => '2', 'unit_price_minor' => '33333', 'tax_code_id' => $this->vat->id],
        ]);

        // The owner holds accounting.invoice.create at the company.
        $converted = $this->api('POST', "crm/quotes/{$quote['id']}/convert", ['target' => 'invoice'])->assertOk()->json('data');
        $this->assertSame(['converted', 'invoice', 'accounting.invoice'], [$converted['status'], $converted['converted_to'], $converted['converted_ref']['type']]);

        [$document, $lines] = $this->inTenant(fn () => [Document::query()->sole(), DocumentLine::query()->orderBy('line_no')->get()]);
        $this->assertSame([$converted['converted_ref']['id'], 'customer_invoice', 'draft', $this->customer->id, 'KES', $this->branchA->id],
            [$document->id, $document->kind, $document->status, $document->party_id, $document->currency, $document->branch_id]);
        $this->assertSame([(int) $quote['totals']['tax_minor'], (int) $quote['totals']['total_minor']], [$document->tax_minor, $document->total_minor]);
        // The discounted line is one unit at its discounted amount; the other keeps its quantity and price.
        $this->assertSame(['1.0000', 277500, 'Widget (3 × 1000.00, 7.5% off)'], [(string) $lines[0]->qty, $lines[0]->unit_price_minor, $lines[0]->description]);
        $this->assertSame(['2.0000', 33333], [(string) $lines[1]->qty, $lines[1]->unit_price_minor]);
        $this->assertStringContainsString($quote['number'], (string) $document->memo);

        // Once only.
        $this->api('POST', "crm/quotes/{$quote['id']}/convert", ['target' => 'invoice'])->assertStatus(409)->assertJsonPath('code', 'quote_converted');
    }

    public function test_conversion_needs_accounting_invoice_create_and_the_revenue_rule(): void
    {
        $this->withAccounting();
        $quote = $this->acceptedQuote();
        // The branch manager may convert quotes but not create invoices: nothing is written.
        $this->inTenant(fn () => $this->roles->get('branch_manager')->revokePermissionTo('accounting.invoice.create'));
        $this->assertFalse($this->inTenant(fn () => $this->manager->can('accounting.invoice.create', Scope::company($this->acme->id))));
        $this->api('POST', "crm/quotes/{$quote['id']}/convert", ['target' => 'invoice'], $this->manager)->assertForbidden();
        $this->assertSame(0, $this->inTenant(fn () => Document::query()->count()));

        $this->inTenant(fn () => PostingRule::query()->where('event_type', 'crm.quote_invoice')->sole()->forceFill(['archived_at' => now()])->save());
        $this->api('POST', "crm/quotes/{$quote['id']}/convert", ['target' => 'invoice'])->assertUnprocessable()->assertJsonPath('code', 'quote_revenue_missing');
        $this->assertSame(['accepted', 0], [$this->inTenant(fn () => Quote::query()->findOrFail($quote['id'])->status), $this->inTenant(fn () => Document::query()->count())]);
    }

    public function test_the_customer_360_view_shows_each_module_only_while_it_is_active_and_readable(): void
    {
        $this->draftQuote();
        $opportunity = $this->api('POST', 'crm/opportunities', ['branch_id' => $this->branchA->id, 'party_id' => $this->customer->id, 'name' => 'Deal', 'value_minor' => '500000', 'currency' => 'KES'], $this->repA)
            ->assertCreated()->json('data');
        $this->api('POST', 'crm/activities', ['kind' => 'call', 'subject' => 'Follow up', 'starts_at' => '2026-10-16T08:00:00Z', 'opportunity_id' => $opportunity['id']], $this->repA)->assertCreated();

        $only = $this->api('GET', "crm/customers/{$this->customer->id}/overview", [], $this->repA)->assertOk()->json('data');
        $this->assertSame([null, null, null], [$only['pos'], $only['loyalty'], $only['accounting']]);
        $this->assertSame([['currency' => 'KES', 'amount_minor' => '500000']], $only['crm']['open_value']);
        $this->assertCount(1, $only['crm']['quotes']);
        $this->assertSame('Follow up', $only['crm']['activities']['upcoming'][0]['subject']);

        // With Accounting on: the owner sees receivables, the rep (no accounting.invoice.view) still does not.
        $this->withAccounting();
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('loyalty'));
        $owner = $this->api('GET', "crm/customers/{$this->customer->id}/overview")->assertOk()->json('data');
        $this->assertSame(['open' => [], 'overdue' => [], 'recent' => []], $owner['accounting']);
        $this->assertSame(['points' => [], 'credit' => []], $owner['loyalty']);
        $this->assertNull($owner['pos']);
        $rep = $this->api('GET', "crm/customers/{$this->customer->id}/overview", [], $this->repA)->assertOk()->json('data');
        $this->assertSame([null, null], [$rep['accounting'], $rep['loyalty']]);
    }

    public function test_invoiced_targets_read_posted_invoices_from_accounting(): void
    {
        $target = $this->api('POST', 'crm/targets', ['company_id' => $this->acme->id, 'subject_type' => 'branch', 'subject_id' => $this->branchA->id, 'metric' => 'invoiced',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount_minor' => '1000000'])->assertCreated()->json('data');
        $this->assertSame([false, null], [$target['available'], $target['actual_minor']]);

        $this->withAccounting();
        $quote = $this->acceptedQuote();
        $invoice = $this->api('POST', "crm/quotes/{$quote['id']}/convert", ['target' => 'invoice'])->json('data.converted_ref.id');
        $this->api('POST', "accounting/documents/{$invoice}/post")->assertOk();

        $read = collect($this->api('GET', 'crm/targets')->assertOk()->json('data'))->firstWhere('id', $target['id']);
        // KES 2,320.00 (two widgets at 16 %) of KES 10,000.00.
        $this->assertSame([true, '232000', '23.2'], [$read['available'], $read['actual_minor'], $read['percent']]);
    }
}
