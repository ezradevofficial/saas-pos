<?php

namespace Tests\Feature\Core\Workflow;

use App\Core\Rbac\Scope;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\Insights\WorkflowInsights;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsWorkflows;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Workflow\Graphs;
use Tests\Support\Workflow\TestOrderType;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * WF-10: stage volumes and bottlenecks (GET workflow-insights): documents
 * at each stage now, entered and left in the period, median and 90th
 * percentile elapsed time in stage, overdue now, the slowest stage; only
 * documents the user may see (RBAC-04); other tenants see nothing.
 */
class WorkflowInsightsApiTest extends TestCase
{
    use BuildsWorkflows, RefreshTenantDatabase;

    private const URL = '/api/v1/workflow-insights';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorkflows();
        // Wednesday 2026-10-07 10:00 in Nairobi.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07T07:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * Five requests: three left Review after 1, 3 and 5 hours and wait at
     * Pay; one at branch B and one started an hour later still wait at
     * Review, past its 8 business hour limit by Friday.
     */
    private function scenario(): void
    {
        $this->publishFlow(Graphs::linear(['review', 'pay'], ['review' => ['due' => ['amount' => 8, 'unit' => 'business_hours']]]));
        $moved = [$this->start($this->document()), $this->start($this->document()), $this->start($this->document())];
        $this->start($this->document([], new DocumentScope($this->acme->id, $this->branchB->id)));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07T08:00:00Z'));
        $this->start($this->document());

        foreach ([1, 3, 5] as $index => $hours) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07T07:00:00Z')->addHours($hours));
            $this->inTenant(fn () => $this->engine()->move($moved[$index], $this->owner));
        }

        // Friday 2026-10-09 15:00 in Nairobi.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09T12:00:00Z'));
    }

    /** @return array<string, array<string, mixed>> stages by node id, without node_id and name */
    private function stages(array $flow): array
    {
        $stages = [];

        foreach ($flow['stages'] as $stage) {
            $stages[$stage['node_id']] = array_diff_key($stage, ['node_id' => true, 'name' => true, 'kind' => true]);
        }

        return $stages;
    }

    public function test_volumes_times_and_the_slowest_stage(): void
    {
        $this->scenario();

        $response = $this->getJson(self::URL.'?from=2026-10-07&to=2026-10-09', $this->headersFor())->assertOk();
        $response->assertJsonPath('meta.from', '2026-10-07')->assertJsonPath('meta.to', '2026-10-09')
            ->assertJsonPath('meta.timezone', 'UTC')->assertJsonPath('meta.time_basis', 'elapsed');
        // The types offered in the filter: those the user may look at.
        $this->assertContains(['key' => TestRequestType::KEY, 'label' => 'Workflows'], $response->json('meta.types'));
        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.document_type', TestRequestType::KEY)
            ->assertJsonPath('data.0.company_id', null)
            ->assertJsonPath('data.0.version', 1)
            ->assertJsonPath('data.0.stages.0.name', 'Review')
            ->assertJsonPath('data.0.stages.0.kind', 'stage');

        // Review: 1 h, 3 h, 5 h → median 3 h; 90th percentile 3 h + 0.8 × 2 h = 4 h 36 min.
        $this->assertSame([
            'review' => ['now' => 2, 'entered' => 5, 'left' => 3, 'median_seconds' => 10800, 'p90_seconds' => 16560, 'overdue' => 2, 'slowest' => true],
            'pay' => ['now' => 3, 'entered' => 3, 'left' => 0, 'median_seconds' => null, 'p90_seconds' => null, 'overdue' => 0, 'slowest' => false],
        ], $this->stages($response->json('data.0')));

        // A later period: nothing entered or left, the waiting documents still count.
        $later = $this->getJson(self::URL.'?from=2026-10-08&to=2026-10-09', $this->headersFor())->assertOk();
        $this->assertSame(
            ['now' => 2, 'entered' => 0, 'left' => 0, 'median_seconds' => null, 'p90_seconds' => null, 'overdue' => 2, 'slowest' => false],
            $this->stages($later->json('data.0'))['review'],
        );

        // The default period is the last 30 days; a company's period is read in its zone.
        $this->getJson(self::URL, $this->headersFor())->assertOk()
            ->assertJsonPath('meta.from', '2026-09-10')->assertJsonPath('meta.to', '2026-10-09')
            ->assertJsonPath('data.0.stages.0.left', 3);
        $this->getJson(self::URL.'?company='.$this->acme->id.'&to=2026-10-07', $this->headersFor())->assertOk()
            ->assertJsonPath('meta.timezone', 'Africa/Nairobi')->assertJsonPath('data.0.stages.0.entered', 5);
    }

    public function test_only_documents_the_user_may_see_count(): void
    {
        $this->scenario();

        // Branch B's manager (the type's view permission at branch B) sees the one document there.
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $mine = $this->getJson(self::URL.'?from=2026-10-07&to=2026-10-09', $this->headersFor($managerB))->assertOk();
        $this->assertSame(
            ['now' => 1, 'entered' => 1, 'left' => 0, 'median_seconds' => null, 'p90_seconds' => null, 'overdue' => 1, 'slowest' => false],
            $this->stages($mine->json('data.0'))['review'],
        );
        $this->assertSame(0, $this->stages($mine->json('data.0'))['pay']['now']);

        // A company accountant sees every document of the company.
        $accountant = $this->userWith('accountant', Scope::company($this->acme->id));
        $this->getJson(self::URL.'?from=2026-10-07&to=2026-10-09', $this->headersFor($accountant))->assertOk()
            ->assertJsonPath('data.0.stages.0.entered', 5);

        // Designing flows is not reading documents: the flow shows, with nothing counted.
        $designer = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Flow designer', ['core.workflow.view']), Scope::tenant());

            return $user;
        });
        $designed = $this->getJson(self::URL.'?from=2026-10-07&to=2026-10-09', $this->headersFor($designer))->assertOk();
        $this->assertSame([0, 0], array_column($designed->json('data.0.stages'), 'entered'));

        // Neither: refused.
        $nobody = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Companies only', ['core.company.view']), Scope::tenant());

            return $user;
        });
        $this->getJson(self::URL, $this->headersFor($nobody))->assertForbidden();
    }

    public function test_filters_are_checked(): void
    {
        $this->scenario();

        $this->getJson(self::URL.'?type='.TestOrderType::KEY, $this->headersFor())->assertOk()->assertJsonPath('data', []);
        $this->getJson(self::URL.'?type=core.nothing', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->getJson(self::URL.'?from=2026-10-09&to=2026-10-01', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson(self::URL.'?from=2025-01-01&to=2026-10-01', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson(self::URL.'?from=9/10/2026', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson(self::URL.'?company=not-a-uuid', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('company');

        // A company the user does not reach.
        $beta = $this->inTenant(fn () => $this->company('Beta'));
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->getJson(self::URL.'?company='.$beta->id, $this->headersFor($managerB))->assertUnprocessable()->assertJsonValidationErrors('company');
        $this->getJson(self::URL.'?company='.$beta->id, $this->headersFor())->assertOk()->assertJsonPath('data.0.stages.0.now', 0);
    }

    public function test_another_tenant_sees_none_of_it(): void
    {
        $this->scenario();
        $other = $this->otherTenant();
        $theirs = $this->headersFor($other['user']);

        $this->getJson(self::URL, $theirs)->assertOk()->assertJsonPath('data', []);
        $this->getJson(self::URL.'?company='.$this->acme->id, $theirs)->assertUnprocessable()->assertJsonValidationErrors('company');
    }

    public function test_percentiles_interpolate_like_postgres(): void
    {
        $this->assertNull(WorkflowInsights::percentile([], 0.5));
        $this->assertSame(7, WorkflowInsights::percentile([7], 0.9));
        $this->assertSame(15, WorkflowInsights::percentile([10, 20], 0.5));
        $this->assertSame(19, WorkflowInsights::percentile([10, 20], 0.9));
    }
}
