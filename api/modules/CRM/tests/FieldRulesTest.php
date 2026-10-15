<?php

namespace Modules\CRM\Tests;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use Modules\CRM\Models\Pipeline;
use Modules\CRM\Tests\Concerns\BuildsCrm;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CRM-09 (RBAC-05): a role whose field rules hide opportunity values and
// lead estimates sees them nowhere: not in records, lists or exports, and
// not through any aggregate (pipeline summary, the pipeline value widget,
// the customer 360 open value, won-value targets). Writing them is refused.
class FieldRulesTest extends TestCase
{
    use BuildsCrm, RefreshTenantDatabase;

    private User $blind;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrm();
        $this->blind = $this->inTenant(function () {
            $role = $this->role('Value-blind manager', [...self::REP_PERMISSIONS, 'crm.scope.branch']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'crm_opportunity', 'field' => 'value_minor', 'mode' => 'hidden']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'crm_lead', 'field' => 'estimated_value_minor', 'mode' => 'hidden']);
            $user = $this->colleague($this->owner, ['name' => 'Blind manager']);
            $this->assign($user, $role, Scope::branch($this->branchA->id));

            return $user;
        });
    }

    public function test_hidden_values_appear_in_no_record_list_export_or_aggregate(): void
    {
        $open = $this->api('POST', 'crm/opportunities', ['branch_id' => $this->branchA->id, 'party_id' => $this->customer->id, 'name' => 'Open deal', 'value_minor' => '3456789', 'currency' => 'KES'], $this->repA)
            ->assertCreated()->json('data');
        $won = $this->api('POST', 'crm/opportunities', ['branch_id' => $this->branchA->id, 'party_id' => $this->customer->id, 'name' => 'Won deal', 'value_minor' => '2345678', 'currency' => 'KES'], $this->repA)
            ->assertCreated()->json('data');
        $this->api('POST', "crm/opportunities/{$won['id']}/win", [], $this->repA)->assertOk();
        $lead = $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Estimate', 'channel' => 'manual', 'estimated_value_minor' => '1234567', 'currency' => 'KES'], $this->repA)
            ->assertCreated()->json('data');

        // Records and lists.
        $this->assertArrayNotHasKey('value', $this->api('GET', "crm/opportunities/{$open['id']}", [], $this->blind)->assertOk()->json('data'));
        $rows = $this->api('GET', 'crm/opportunities?status=all', [], $this->blind)->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame([], array_values(array_filter($rows, fn ($row) => array_key_exists('value', $row) || array_key_exists('weighted_minor', $row))));
        $this->assertArrayNotHasKey('estimated_value', $this->api('GET', "crm/leads/{$lead['id']}", [], $this->blind)->assertOk()->json('data'));
        $this->assertArrayNotHasKey('estimated_value', $this->api('GET', 'crm/leads', [], $this->blind)->assertOk()->json('data.0'));

        // Exports.
        foreach (['crm/opportunities?status=all&format=csv' => ['34,567', '34567', '23,456', '23456'], 'crm/leads?format=csv' => ['12,345', '12345']] as $uri => $amounts) {
            $csv = $this->get("/api/v1/{$uri}", $this->headersFor($this->blind))->assertOk()->streamedContent();
            foreach ($amounts as $amount) {
                $this->assertStringNotContainsString($amount, $csv, $uri);
            }
        }

        // Aggregates: counts only.
        $pipeline = $this->inTenant(fn () => Pipeline::query()->where('company_id', $this->acme->id)->firstOrFail()->id);
        $summary = $this->api('GET', "crm/pipeline-summary?pipeline={$pipeline}", [], $this->blind)->assertOk()->json('data');
        $this->assertSame(1, array_sum(array_column($summary['stages'], 'count')));
        $this->assertSame([null], array_values(array_unique(array_column($summary['stages'], 'values'), SORT_REGULAR)));
        $this->assertSame([1, null], [$summary['won']['count'], $summary['won']['values']]);
        $this->assertNotNull($this->api('GET', "crm/pipeline-summary?pipeline={$pipeline}", [], $this->manager)->json('data.won.values'), 'others still see them');

        $kpi = $this->api('GET', 'dashboard/sources/crm.pipeline_value', [], $this->blind)->assertOk()->json('data');
        $this->assertSame(['number', '1'], [$kpi['kind'], $kpi['value']]);
        $this->assertSame([], $this->api('GET', 'dashboard/sources/crm.pipeline_value?widget=chart', [], $this->blind)->assertOk()->json('data.points'));

        $this->assertNull($this->api('GET', "crm/customers/{$this->customer->id}/overview", [], $this->blind)->assertOk()->json('data.crm.open_value'));

        $target = $this->api('POST', 'crm/targets', ['company_id' => $this->acme->id, 'subject_type' => 'branch', 'subject_id' => $this->branchA->id, 'metric' => 'won_value',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount_minor' => '5000000'])->assertCreated()->json('data');
        $this->assertSame('2345678', $target['actual_minor']);
        $seen = collect($this->api('GET', 'crm/targets', [], $this->blind)->assertOk()->json('data'))->firstWhere('id', $target['id']);
        $this->assertSame([null, null, false, 'field_hidden'], [$seen['actual_minor'], $seen['percent'], $seen['available'], $seen['unavailable']]);

        // Writing a hidden field is refused.
        $this->api('PATCH', "crm/opportunities/{$open['id']}", ['value_minor' => '1'], $this->blind)->assertUnprocessable()->assertJsonPath('code', 'field_readonly');
        $this->api('PATCH', "crm/leads/{$lead['id']}", ['estimated_value_minor' => '1'], $this->blind)->assertUnprocessable()->assertJsonPath('code', 'field_readonly');
    }
}
