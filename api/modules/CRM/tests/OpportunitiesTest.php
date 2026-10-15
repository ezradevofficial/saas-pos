<?php

namespace Modules\CRM\Tests;

use App\Core\MasterData\Parties\Party;
use Illuminate\Support\Facades\Event;
use Modules\CRM\Events\OpportunityLost;
use Modules\CRM\Events\OpportunityWon;
use Modules\CRM\Models\Option;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Tests\Concerns\BuildsCrm;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CRM-01 to CRM-04, CRM-14 to CRM-16: settings, pipelines and stages as
// configuration, options and teams; opportunities moved through stages,
// won with a base value (CUR-04), lost with a reason, reopened; the
// pipeline's value by stage; accepting a quote can win its opportunity.
class OpportunitiesTest extends TestCase
{
    use BuildsCrm, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrm();
    }

    private function opportunity(array $extra = []): array
    {
        return $this->api('POST', 'crm/opportunities', ['branch_id' => $this->branchA->id, 'party_id' => $this->customer->id, 'name' => 'Fit-out',
            'value_minor' => '1000000', 'currency' => 'KES', ...$extra], $this->repA)->assertCreated()->json('data');
    }

    public function test_a_company_gets_a_default_pipeline_and_lists_in_its_language_and_configures_them(): void
    {
        $settings = $this->api('GET', "crm/companies/{$this->acme->id}/settings", [], $this->repA)->assertOk()->json('data');
        $this->assertSame([null, 30, true, 'KES', false], [$settings['quote_approval_minor'], $settings['quote_validity_days'], $settings['lost_reason_required'], $settings['currency'], $settings['can']['manage']]);

        $pipelines = $this->api('GET', "crm/pipelines?company={$this->acme->id}", [], $this->repA)->assertOk()->json('data');
        $this->assertSame(['Sales'], array_column($pipelines, 'name'));
        $this->assertSame([['Qualification', 10], ['Needs analysis', 25], ['Proposal', 50], ['Negotiation', 75]],
            array_map(fn (array $s) => [$s['name'], $s['probability']], $pipelines[0]['stages']));
        $this->assertSame(['Price too high', 'Went to a competitor', 'No budget', 'No decision', 'Timing'],
            array_column($this->api('GET', "crm/options?company={$this->acme->id}&kind=lost_reason", [], $this->repA)->json('data'), 'name'));

        // The admin configures: a second pipeline as the default, a stage moved first, the threshold.
        $projects = $this->api('POST', 'crm/pipelines', ['company_id' => $this->acme->id, 'name' => 'Projects', 'is_default' => true,
            'stages' => [['name' => 'Survey', 'probability' => 20], ['name' => 'Bid', 'probability' => 60]]])->assertCreated()->json('data');
        $this->api('POST', "crm/pipelines/{$projects['id']}/stages", ['name' => 'Lead in', 'probability' => 5, 'position' => 1])->assertCreated()
            ->assertJsonPath('data.stages.0.name', 'Lead in');
        $this->assertSame(['Projects'], $this->inTenant(fn () => Pipeline::query()->where('is_default', true)->pluck('name')->all()));
        $this->api('PUT', "crm/companies/{$this->acme->id}/settings", ['quote_approval_minor' => '5000000', 'lost_reason_required' => false])->assertOk()
            ->assertJsonPath('data.quote_approval_minor', '5000000');
        $this->api('POST', "crm/pipelines/{$projects['id']}/archive")->assertStatus(409)->assertJsonPath('code', 'pipeline_default');
        $this->api('POST', 'crm/options', ['company_id' => $this->acme->id, 'kind' => 'source', 'name' => 'Radio'])->assertCreated()->assertJsonPath('data.name', 'Radio');
    }

    public function test_stage_moves_follow_the_stage_probability_and_a_stage_in_use_stays(): void
    {
        $opportunity = $this->opportunity(['expected_close_on' => '2026-11-15']);
        $this->assertSame(['Qualification', 10, '100000'], [$opportunity['stage']['name'], $opportunity['probability'], $opportunity['weighted_minor']]);
        $proposal = collect($this->api('GET', "crm/pipelines?company={$this->acme->id}")->json('data.0.stages'))->firstWhere('name', 'Proposal');

        $moved = $this->api('PATCH', "crm/opportunities/{$opportunity['id']}", ['stage_id' => $proposal['id']], $this->repA)->assertOk()->json('data');
        $this->assertSame(['Proposal', 50, '500000'], [$moved['stage']['name'], $moved['probability'], $moved['weighted_minor']]);
        $this->api('PATCH', "crm/opportunities/{$opportunity['id']}", ['probability' => 65], $this->repA)->assertOk()->assertJsonPath('data.probability', 65);

        $this->api('POST', "crm/stages/{$proposal['id']}/archive")->assertStatus(409)->assertJsonPath('code', 'stage_in_use');

        $summary = $this->api('GET', "crm/pipeline-summary?pipeline={$opportunity['pipeline']['id']}", [], $this->repA)->assertOk()->json('data');
        $stage = collect($summary['stages'])->firstWhere('name', 'Proposal');
        $this->assertSame([1, [['currency' => 'KES', 'amount_minor' => '1000000', 'weighted_minor' => '650000']]], [$stage['count'], $stage['values']]);
    }

    public function test_winning_freezes_the_base_value_and_losing_needs_a_reason(): void
    {
        Event::fake([OpportunityWon::class, OpportunityLost::class]);
        $this->api('GET', "crm/companies/{$this->acme->id}/settings")->assertOk();
        $usd = $this->opportunity(['currency' => 'USD', 'value_minor' => '100000']);
        $won = $this->api('POST', "crm/opportunities/{$usd['id']}/win", ['note' => 'Signed'], $this->repA)->assertOk()->json('data');
        // USD 1,000.00 at 130 = KES 130,000.00.
        $this->assertSame(['won', 100, '2026-10-15', ['amount_minor' => '13000000', 'currency' => 'KES']], [$won['status'], $won['probability'], $won['closed_on'], $won['base_value']]);
        Event::assertDispatched(OpportunityWon::class, fn (OpportunityWon $e) => $e->baseValueMinor === '13000000');
        $this->api('PATCH', "crm/opportunities/{$usd['id']}", ['value_minor' => '1'], $this->repA)->assertStatus(409)->assertJsonPath('code', 'opportunity_closed');

        $lost = $this->opportunity();
        $this->api('POST', "crm/opportunities/{$lost['id']}/lose", [], $this->repA)->assertUnprocessable()->assertJsonPath('code', 'reason_required');
        $reason = $this->inTenant(fn () => Option::query()->where('kind', 'lost_reason')->where('name', 'Price too high')->value('id'));
        $this->api('POST', "crm/opportunities/{$lost['id']}/lose", ['reason_id' => $reason], $this->repA)->assertOk()->assertJsonPath('data.reason.name', 'Price too high')
            ->assertJsonPath('data.probability', 0);
        Event::assertDispatched(OpportunityLost::class);

        $reopened = $this->api('POST', "crm/opportunities/{$lost['id']}/reopen", [], $this->repA)->assertOk()->json('data');
        $this->assertSame(['open', 10, null], [$reopened['status'], $reopened['probability'], $reopened['reason']]);
    }

    public function test_accepting_a_quote_can_win_its_opportunity(): void
    {
        $opportunity = $this->opportunity();
        $quote = $this->draftQuote(['opportunity_id' => $opportunity['id']]);
        $this->api('POST', "crm/quotes/{$quote['id']}/submit", [], $this->repA)->assertOk();
        $this->api('POST', "crm/quotes/{$quote['id']}/send", [], $this->repA)->assertOk()->assertJsonPath('data.status', 'sent');
        $this->api('POST', "crm/quotes/{$quote['id']}/accept", ['win_opportunity' => true], $this->repA)->assertOk()->assertJsonPath('data.status', 'accepted');

        $won = $this->api('GET', "crm/opportunities/{$opportunity['id']}", [], $this->repA)->assertOk()->json('data');
        $this->assertSame('won', $won['status']);
        $this->assertSame([$quote['id']], array_column($won['quotes'], 'id'));
        // The send was logged as an email activity of the quote.
        $this->assertSame('email', $this->api('GET', 'crm/activities?from=2026-10-15&to=2026-10-15', [], $this->repA)->json('data.0.kind'));

        // A quote for another customer than its opportunity's is refused.
        $other = $this->inTenant(fn () => Party::create(['kind' => 'person', 'name' => 'Other', 'roles' => ['customer'], 'company_id' => $this->acme->id]));
        $this->api('POST', 'crm/quotes', ['branch_id' => $this->branchA->id, 'party_id' => $other->id, 'opportunity_id' => $this->opportunity()['id'], 'lines' => [['item_id' => $this->widget->id, 'qty' => '1']]], $this->repA)
            ->assertUnprocessable()->assertJsonPath('code', 'opportunity_party_mismatch');
    }

    public function test_an_expired_quote_cannot_be_accepted_but_is_shared_until_then(): void
    {
        $quote = $this->draftQuote(['valid_until' => '2026-10-20']);
        $this->api('POST', "crm/quotes/{$quote['id']}/submit", [], $this->repA)->assertOk();
        $share = $this->api('POST', "crm/quotes/{$quote['id']}/share", [], $this->repA)->assertCreated()->json('data');
        $this->assertStringStartsWith('https://wa.me/?text=', $share['whatsapp_url']);
        $this->get(parse_url($share['url'], PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->api('GET', "crm/quotes/{$quote['id']}/pdf", [], $this->repA)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertCount(1, $this->api('GET', "crm/quotes/{$quote['id']}/shares", [], $this->repA)->json('data'));
        $this->api('POST', "crm/quotes/{$quote['id']}/shares/{$share['id']}/revoke", [], $this->repA)->assertOk()->assertJsonPath('data.status', 'revoked');

        $this->clockTo('2026-10-22T09:00:00Z');
        $this->api('GET', "crm/quotes/{$quote['id']}", [], $this->repA)->assertJsonPath('data.expired', true);
        $this->api('POST', "crm/quotes/{$quote['id']}/accept", [], $this->repA)->assertStatus(409)->assertJsonPath('code', 'quote_expired');
        $this->api('POST', "crm/quotes/{$quote['id']}/decline", ['reason' => 'Too late'], $this->repA)->assertOk()->assertJsonPath('data.status', 'declined');
    }
}
