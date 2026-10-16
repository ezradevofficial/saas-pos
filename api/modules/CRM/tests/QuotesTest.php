<?php

namespace Modules\CRM\Tests;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\Rbac\Models\LimitRule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Modules\CRM\Events\QuoteApproved;
use Modules\CRM\Models\Quote;
use Modules\CRM\Models\Settings;
use Modules\CRM\Quotes\QuoteType;
use Modules\CRM\Tests\Concerns\BuildsCrm;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CRM-17 to CRM-21: quote maths in minor units, tax from core (never
// invented), RBAC-06 discount limits, prices from the price list, approval
// through the `crm.quote` flow above the company's threshold.
class QuotesTest extends TestCase
{
    use BuildsCrm, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrm();
    }

    public function test_lines_and_totals_are_exact_in_minor_units_with_list_prices_discounts_and_core_tax(): void
    {
        // Widget KES 1,000.00 × 3 at 16 % exclusive, 7.5 % off; a description line of 2.5 × KES 333.33 at the test VAT.
        $quote = $this->draftQuote(lines: [
            ['item_id' => $this->widget->id, 'qty' => '3', 'discount_percent' => '7.5'],
            ['description' => 'Installation', 'qty' => '2.5', 'unit_price_minor' => '33333', 'tax_code_id' => $this->vat->id],
        ]);

        [$widget, $install] = $quote['lines'];
        // gross 300000; discount round(300000 × 7.5 %) = 22500; net 277500; tax round(277500 × 16 %) = 44400.
        $this->assertSame(['100000', '100000', '300000', '22500', '277500', '44400', '321900', '16'],
            [$widget['list_price_minor'], $widget['unit_price_minor'], $widget['gross_minor'], $widget['discount_minor'], $widget['net_minor'], $widget['tax_minor'], $widget['total_minor'], $widget['tax_rate']]);
        $this->assertSame('VAT_T', $widget['tax_code']['code']);
        // gross round(2.5 × 33333 = 83332.5) = 83333; tax round(83333 × 16 %) = 13333 (13333.28).
        $this->assertSame(['83333', '0', '83333', '13333', '96666'], [$install['gross_minor'], $install['discount_minor'], $install['net_minor'], $install['tax_minor'], $install['total_minor']]);
        $this->assertSame(['subtotal_minor' => '383333', 'discount_minor' => '22500', 'tax_minor' => '57733', 'total_minor' => '418566'], $quote['totals']);
        $this->assertSame('7.5', $quote['max_discount_percent']);
        $this->assertSame(['KES', 'Retail', false, '2026-10-15', '2026-11-14'], [$quote['currency'], $quote['price_list']['name'], $quote['tax_inclusive'], $quote['quote_date'], $quote['valid_until']]);
    }

    public function test_an_inclusive_list_takes_the_tax_out_of_the_amount(): void
    {
        $this->inTenant(fn () => $this->list->forceFill(['tax_inclusive' => true])->save());
        $quote = $this->draftQuote(lines: [['item_id' => $this->widget->id, 'qty' => '1', 'discount_percent' => '10']]);

        // amount 90000 inclusive: tax round(90000 × 16 / 116) = 12414; net 77586; total 90000.
        $line = $quote['lines'][0];
        $this->assertSame(['90000', '12414', '77586', '90000'], [(string) ($line['gross_minor'] - $line['discount_minor']), $line['tax_minor'], $line['net_minor'], $line['total_minor']]);
        $this->assertTrue($quote['tax_inclusive']);
        $this->assertSame('90000', $quote['totals']['total_minor']);
    }

    public function test_a_currency_without_decimals_rounds_to_whole_units(): void
    {
        $this->inTenant(function () {
            app(TenantCurrencies::class)->activate('CDF');
            $this->customer->forceFill(['currency' => 'CDF'])->save();
        });

        $quote = $this->draftQuote(lines: [['description' => 'Service', 'qty' => '3', 'unit_price_minor' => '3335', 'discount_percent' => '1.5', 'tax_code_id' => $this->vat->id]]);

        // No CDF list: the typed price. gross 10005; discount round(150.075) = 150; net 9855; tax round(1576.8) = 1577.
        $this->assertSame('CDF', $quote['currency']);
        $this->assertNull($quote['price_list']);
        $this->assertSame(['10005', '150', '9855', '1577', '11432'], [$quote['lines'][0]['gross_minor'], $quote['lines'][0]['discount_minor'], $quote['lines'][0]['net_minor'], $quote['lines'][0]['tax_minor'], $quote['lines'][0]['total_minor']]);
    }

    public function test_tax_comes_from_core_and_a_rate_needed_saves_a_draft_but_refuses_to_submit(): void
    {
        // The gadget's code has no rate: kept without tax, marked "Rate needed" (never invented).
        $quote = $this->draftQuote(lines: [['item_id' => $this->gadget->id, 'qty' => '1']]);
        $this->assertSame(['0', null, true, 'VAT_P'], [$quote['lines'][0]['tax_minor'], $quote['lines'][0]['tax_rate'], $quote['lines'][0]['rate_needed'], $quote['lines'][0]['tax_code']['code']]);

        $this->api('POST', "crm/quotes/{$quote['id']}/submit", [], $this->repA)->assertUnprocessable()->assertJsonPath('code', 'tax_rate_missing');
        $this->assertSame('draft', $this->inTenant(fn () => Quote::query()->findOrFail($quote['id'])->status));

        // An item without a tax category is refused on the line; a typed code on an item line is ignored (tax from core).
        $this->api('POST', 'crm/quotes', ['branch_id' => $this->branchA->id, 'party_id' => $this->customer->id, 'lines' => [['item_id' => $this->untaxed->id, 'qty' => '1']]], $this->repA)
            ->assertUnprocessable()->assertJsonPath('code', 'tax_category_missing')->assertJsonValidationErrors('lines.0.item_id');
        $typed = $this->draftQuote(lines: [['item_id' => $this->widget->id, 'qty' => '1', 'tax_code_id' => $this->pending->id]]);
        $this->assertSame('VAT_T', $typed['lines'][0]['tax_code']['code']);

        // A description line needs a company tax code.
        $this->api('POST', 'crm/quotes', ['branch_id' => $this->branchA->id, 'party_id' => $this->customer->id, 'lines' => [['description' => 'Labour', 'qty' => '1', 'unit_price_minor' => '1000']]], $this->repA)
            ->assertUnprocessable()->assertJsonPath('code', 'tax_code_required');
    }

    public function test_the_preview_prices_without_saving_and_lists_every_problem(): void
    {
        $response = $this->api('POST', 'crm/quotes/preview', ['branch_id' => $this->branchA->id, 'party_id' => $this->customer->id, 'lines' => [
            ['item_id' => $this->widget->id, 'qty' => '1', 'discount_percent' => '25'],
            ['item_id' => $this->gadget->id, 'qty' => '1'],
            ['item_id' => $this->widget->id, 'qty' => '1', 'unit_price_minor' => '90000'],
        ]], $this->repA)->assertOk();

        $this->assertSame(['discount_needs_approval'], array_column($response->json('data.lines.0.problems'), 'code'));
        $this->assertTrue($response->json('data.discount_over_limit'));
        $this->assertTrue($response->json('data.lines.1.rate_needed'));
        $this->assertSame(['price_override_not_allowed'], array_column($response->json('data.lines.2.problems'), 'code'));
        $this->assertSame(0, $this->inTenant(fn () => Quote::query()->count()));
    }

    public function test_discounts_stay_within_the_rbac_06_limit_and_need_a_rule(): void
    {
        // The rep's limit is 10 %: within it, nothing to approve.
        $this->assertFalse($this->draftQuote(lines: [['item_id' => $this->widget->id, 'qty' => '1', 'discount_percent' => '10']])['discount_over_limit']);

        // Owner decision A (2026-10-16): the Branch Manager template allows 10 % on quotes; above it, approval.
        $this->assertFalse($this->draftQuote(lines: [['item_id' => $this->widget->id, 'qty' => '1', 'discount_percent' => '10']], as: $this->manager)['discount_over_limit']);
        $this->assertTrue($this->draftQuote(lines: [['item_id' => $this->widget->id, 'qty' => '1', 'discount_percent' => '10.5']], as: $this->manager)['discount_over_limit']);

        // Quotes read their own limit, never the till's: a 30 % till discount leaves the quote limit at 10 %.
        $tillRole = $this->inTenant(function () {
            $role = $this->role('Till lead', ['pos.sale.view']);
            $role->limitRules()->create(['key' => 'max_discount_percent', 'value' => '30']);
            $this->assign($this->manager, $role, Scope::branch($this->branchA->id));

            return $role;
        });
        $this->assertTrue($this->draftQuote(lines: [['item_id' => $this->widget->id, 'qty' => '1', 'discount_percent' => '30']], as: $this->manager)['discount_over_limit']);
        $this->inTenant(fn () => $tillRole->limitRules()->create(['key' => 'max_quote_discount_percent', 'value' => '30']));
        $this->assertFalse($this->draftQuote(lines: [['item_id' => $this->widget->id, 'qty' => '1', 'discount_percent' => '30']], as: $this->manager)['discount_over_limit']);

        // A role holding crm.quote.discount without a quote rule allows none.
        $this->inTenant(fn () => $this->repRole->limitRules()->delete());
        $this->assertTrue($this->draftQuote(lines: [['item_id' => $this->widget->id, 'qty' => '1', 'discount_percent' => '1']])['discount_over_limit']);

        // The Owner has no limit.
        $this->assertFalse($this->draftQuote(lines: [['item_id' => $this->widget->id, 'qty' => '1', 'discount_percent' => '80']], as: $this->owner)['discount_over_limit']);

        // Without crm.quote.discount, none.
        $this->inTenant(fn () => $this->repRole->revokePermissionTo('crm.quote.discount'));
        $this->api('POST', 'crm/quotes', ['branch_id' => $this->branchA->id, 'party_id' => $this->customer->id, 'lines' => [['item_id' => $this->widget->id, 'qty' => '1', 'discount_percent' => '5']]], $this->repA)
            ->assertUnprocessable()->assertJsonPath('code', 'discount_not_allowed');
    }

    public function test_a_discount_above_the_limit_is_saved_but_approved_by_a_manager_before_it_is_sent(): void
    {
        // Owner decision A: no threshold is set, yet the 25 % line (limit 10 %) routes the quote through the crm.quote flow.
        $quote = $this->draftQuote(lines: [['item_id' => $this->widget->id, 'qty' => '1', 'discount_percent' => '25']]);
        $this->assertSame(['draft', true, '25'], [$quote['status'], $quote['discount_over_limit'], $quote['lines'][0]['discount_percent']]);

        $submitted = $this->api('POST', "crm/quotes/{$quote['id']}/submit", [], $this->repA)->assertOk()->json('data');
        $this->assertSame(['pending', true, 'discount_above_limit'], [$submitted['status'], $submitted['needs_approval'], $submitted['approval_reason']]);
        $this->api('POST', "crm/quotes/{$quote['id']}/send", ['email' => 'buyer@duka.example.com'], $this->repA)->assertStatus(409)->assertJsonPath('code', 'quote_not_approved');

        $this->approveFlow(QuoteType::KEY, $quote['id']);
        $this->api('POST', "crm/quotes/{$quote['id']}/send", ['email' => 'buyer@duka.example.com'], $this->repA)->assertOk()->assertJsonPath('data.status', 'sent');

        // Within the limit and below the threshold: approved at once, no reason.
        $plain = $this->draftQuote();
        $this->assertNull($this->api('POST', "crm/quotes/{$plain['id']}/submit", [], $this->repA)->assertOk()->json('data.approval_reason'));
        $this->assertSame('approved', $this->api('GET', "crm/quotes/{$plain['id']}", [], $this->repA)->json('data.status'));
    }

    public function test_an_edit_reprices_list_priced_lines_and_checks_the_editor(): void
    {
        $quote = $this->draftQuote(lines: [['item_id' => $this->widget->id, 'qty' => '1', 'discount_percent' => '8']]);
        $typed = $this->draftQuote(lines: [['item_id' => $this->widget->id, 'qty' => '1', 'unit_price_minor' => '90000']], as: $this->owner);
        $this->inTenant(function () {
            ItemPrice::create(['price_list_id' => $this->list->id, 'item_id' => $this->widget->id, 'uom_id' => $this->each->id, 'amount_minor' => '120000', 'currency' => 'KES', 'effective_from' => '2026-11-01']);
        });

        // A new date reads the list again: the list-priced line follows it, a typed price stays.
        $moved = $this->api('PATCH', "crm/quotes/{$quote['id']}", ['quote_date' => '2026-11-02'], $this->repA)->assertOk()->json('data');
        $this->assertSame(['120000', '120000'], [$moved['lines'][0]['list_price_minor'], $moved['lines'][0]['unit_price_minor']]);
        $kept = $this->api('PATCH', "crm/quotes/{$typed['id']}", ['quote_date' => '2026-11-02'], $this->owner)->assertOk()->json('data');
        $this->assertSame(['120000', '90000'], [$kept['lines'][0]['list_price_minor'], $kept['lines'][0]['unit_price_minor']]);

        // The editor's own limit applies to the saved discounts (RBAC-06).
        $this->inTenant(fn () => LimitRule::query()->where('role_id', $this->repRole->id)->update(['value' => '5']));
        $this->assertTrue($this->api('PATCH', "crm/quotes/{$quote['id']}", ['terms' => 'Net 30'], $this->repA)->assertOk()->json('data.discount_over_limit'));
    }

    public function test_a_price_other_than_the_list_price_needs_crm_quote_price(): void
    {
        $this->api('POST', 'crm/quotes', ['branch_id' => $this->branchA->id, 'party_id' => $this->customer->id, 'lines' => [['item_id' => $this->widget->id, 'qty' => '1', 'unit_price_minor' => '90000']]], $this->repA)
            ->assertUnprocessable()->assertJsonPath('code', 'price_override_not_allowed');
        // The list price typed again is not an override; the owner (crm.quote.price) may change it.
        $this->draftQuote(lines: [['item_id' => $this->widget->id, 'qty' => '1', 'unit_price_minor' => '100000']]);
        $quote = $this->draftQuote(lines: [['item_id' => $this->widget->id, 'qty' => '1', 'unit_price_minor' => '90000']], as: $this->owner);
        $this->assertSame(['100000', '90000'], [$quote['lines'][0]['list_price_minor'], $quote['lines'][0]['unit_price_minor']]);
    }

    public function test_below_the_threshold_a_quote_is_approved_at_once_and_numbered(): void
    {
        Event::fake([QuoteApproved::class]);
        $quote = $this->draftQuote();
        $submitted = $this->api('POST', "crm/quotes/{$quote['id']}/submit", [], $this->repA)->assertOk()->json('data');

        $this->assertSame('QT-A-2026-00001', $submitted['number']);
        $this->assertSame(['KES', '232000'], [$submitted['base_totals']['currency'], $submitted['base_totals']['total_minor']]);
        $this->assertFalse($submitted['needs_approval']);
        $this->assertSame('approved', $this->api('GET', "crm/quotes/{$quote['id']}", [], $this->repA)->json('data.status'));
        Event::assertDispatched(QuoteApproved::class);
    }

    public function test_above_the_threshold_a_branch_manager_approves_and_the_creator_cannot(): void
    {
        $this->inTenant(fn () => Settings::query()->updateOrCreate(['company_id' => $this->acme->id], ['quote_approval_minor' => 200000]));
        $quote = $this->draftQuote();
        $submitted = $this->api('POST', "crm/quotes/{$quote['id']}/submit", [], $this->repA)->assertOk()->json('data');
        $this->assertSame(['pending', true], [$submitted['status'], $submitted['needs_approval']]);

        // APR-07: the rep is never the approver (not even with the permission); the manager approves.
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $quote['id'])->sole()->id);
        $this->inTenant(fn () => $this->repRole->givePermissionTo('crm.quote.approve'));
        $this->assertContains($this->api('POST', "approvals/{$approval}/approve", [], $this->repA)->getStatusCode(), [403, 404, 422]);
        $this->approveFlow(QuoteType::KEY, $quote['id']);
        $this->assertSame('approved', $this->api('GET', "crm/quotes/{$quote['id']}", [], $this->repA)->json('data.status'));
    }

    public function test_a_rejected_quote_is_edited_back_into_a_draft_and_submitted_again(): void
    {
        $this->inTenant(fn () => Settings::query()->updateOrCreate(['company_id' => $this->acme->id], ['quote_approval_minor' => 0]));
        $quote = $this->draftQuote();
        $this->api('POST', "crm/quotes/{$quote['id']}/submit", [], $this->repA)->assertOk();
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $quote['id'])->sole()->id);
        $this->api('POST', "approvals/{$approval}/reject", ['comment' => 'Too cheap'], $this->manager)->assertOk();
        $this->assertSame('rejected', $this->api('GET', "crm/quotes/{$quote['id']}", [], $this->repA)->json('data.status'));

        $edited = $this->api('PATCH', "crm/quotes/{$quote['id']}", ['terms' => 'Net 30'], $this->repA)->assertOk()->json('data');
        $this->assertSame(['draft', 'Net 30', 'QT-A-2026-00001'], [$edited['status'], $edited['terms'], $edited['number']]);
        $this->api('POST', "crm/quotes/{$quote['id']}/submit", [], $this->repA)->assertOk()->assertJsonPath('data.number', 'QT-A-2026-00001');
    }

    public function test_a_foreign_currency_quote_freezes_the_rate_and_base_amounts_at_submission(): void
    {
        $this->inTenant(fn () => $this->customer->forceFill(['currency' => 'USD'])->save());
        $quote = $this->draftQuote(lines: [['description' => 'Consulting', 'qty' => '1', 'unit_price_minor' => '10000', 'tax_code_id' => $this->vat->id]]);
        $submitted = $this->api('POST', "crm/quotes/{$quote['id']}/submit", [], $this->repA)->assertOk()->json('data');

        // USD 116.00 at 130 = KES 15,080.00.
        $this->assertSame(['USD', '11600', 'KES', '1508000'], [$submitted['currency'], $submitted['totals']['total_minor'], $submitted['base_totals']['currency'], $submitted['base_totals']['total_minor']]);
        $this->assertSame('130.00000000', $submitted['fx']['rate']);
    }

    public function test_quotes_need_the_module(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('crm'));
        $this->api('GET', 'crm/quotes')->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }
}
