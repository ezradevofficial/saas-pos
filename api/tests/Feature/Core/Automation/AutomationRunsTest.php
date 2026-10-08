<?php

namespace Tests\Feature\Core\Automation;

use App\Core\Audit\AuditEntry;
use App\Core\Automation\Events\RecordChanged;
use App\Core\Automation\Jobs\RunAutomationRule;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Automation\Runtime\RuleRunner;
use App\Core\Automation\Runtime\Rules;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\BuildsAutomation;
use Tests\Concerns\ReadsListExports;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Automation\TestTaskType;
use Tests\Support\Workflow\TestDocuments;
use Tests\TestCase;

/**
 * AUTO-05, AUTO-06: every run is logged with its trigger, outcome and
 * errors; a failing run is retried (3 attempts with backoff) and, failing
 * for good, alerts the tenant's automation administrators; conditions
 * false skip it; a rule never re-triggers itself, and a chain of rules
 * stops past depth 3; runs beyond the tenant's or the rule's per-minute
 * limit are throttled. The run log API lists and shows runs of the rules
 * the user may see.
 */
class AutomationRunsTest extends TestCase
{
    use BuildsAutomation, ReadsListExports, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpAutomation();
    }

    public function test_conditions_false_skip_the_run_and_log_the_checks(): void
    {
        $rule = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'urgent', 'value' => true]], [
            'conditions' => ['all' => [['field' => 'amount', 'op' => 'gt', 'value' => $this->kes(100000)], ['field' => 'status', 'op' => 'eq', 'value' => 'open']]],
        ]);

        $small = $this->createTask(['amount' => $this->kes(5000)]);
        $big = $this->createTask(['amount' => $this->kes(500000)]);

        [$skipped, $ran] = $this->runs($rule)->all();
        $this->assertSame(AutomationRun::SKIPPED, $skipped->outcome);
        $this->assertSame($small, $skipped->document_id);
        $this->assertFalse($skipped->conditions['passed']);
        $this->assertSame(['amount', 'status'], array_column($skipped->conditions['checks'], 'field'));
        $this->assertArrayNotHasKey('urgent', $this->taskValues($small));
        $this->assertSame(AutomationRun::SUCCEEDED, $ran->outcome);
        $this->assertTrue($this->taskValues($big)['urgent']);
    }

    public function test_a_failing_run_is_retried_and_succeeds(): void
    {
        $rule = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'urgent', 'value' => true]]);
        TestTaskType::$failNext = 2;

        $id = $this->createTask();

        $run = $this->runs($rule)->sole();
        $this->assertSame(AutomationRun::SUCCEEDED, $run->outcome);
        $this->assertSame(3, $run->attempts);
        $this->assertNull($run->error);
        $this->assertTrue($this->taskValues($id)['urgent']);
    }

    public function test_retries_wait_with_backoff_on_the_queue(): void
    {
        $rule = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'urgent', 'value' => true]]);
        TestTaskType::$failNext = 1;
        Bus::fake();

        $this->createTask();
        $run = $this->runs($rule)->sole();
        $this->assertSame(AutomationRun::QUEUED, $run->outcome);

        // The worker runs it: the first attempt fails and a second is queued with a delay.
        $this->inTenant(fn () => app(RuleRunner::class)->execute($run->id));
        $this->assertSame(AutomationRun::RETRYING, $this->inTenant(fn () => $run->fresh()->outcome));
        $this->assertSame('Something went wrong while running the rule. If it keeps failing, check its actions or contact support.', $this->inTenant(fn () => $run->fresh()->error));
        Bus::assertDispatched(RunAutomationRule::class, fn ($job) => $job->runId === $run->id && $job->delay === 30);
    }

    public function test_a_run_failing_every_attempt_fails_and_alerts_the_tenants_automation_admins(): void
    {
        $admin = $this->userWith('admin', Scope::tenant());
        $companyAdmin = $this->userWith('admin', Scope::company($this->acme->id));
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $rule = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'urgent', 'value' => true]], ['name' => 'Flag urgent tasks']);
        TestTaskType::$failNext = 10;

        $this->createTask();

        $run = $this->runs($rule)->sole();
        $this->assertSame(AutomationRun::FAILED, $run->outcome);
        $this->assertSame(3, $run->attempts);
        $this->assertSame('Something went wrong while running the rule. If it keeps failing, check its actions or contact support.', $run->error);
        $this->assertEquals([['type' => 'update_field', 'status' => 'failed', 'error' => $run->error]], $run->actions);
        $this->assertStringNotContainsString('task store is down', json_encode($run->toArray()), 'raw exception text never reaches the log');

        $this->inTenant(function () use ($admin, $companyAdmin, $manager, $run, $rule) {
            $alerts = InAppNotification::query()->where('event_type', 'core.automation.failed')->get();
            $this->assertEqualsCanonicalizing([$this->owner->id, $admin->id], $alerts->pluck('user_id')->all());
            $this->assertNotContains($companyAdmin->id, $alerts->pluck('user_id')->all());
            $this->assertNotContains($manager->id, $alerts->pluck('user_id')->all());
            $this->assertSame('The automation rule “Flag urgent tasks” failed', $alerts->first()->subject);
            $this->assertSame('/automation-rules/'.$rule->id.'/runs/'.$run->id, $alerts->first()->link);
        });
    }

    public function test_a_rule_whose_user_was_deactivated_is_switched_off_and_admins_alerted_once(): void
    {
        $author = $this->userWith('admin', Scope::tenant());
        $rule = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'urgent', 'value' => true]], ['name' => 'Flag'], $author);
        $this->inTenant(fn () => $author->forceFill(['status' => 'deactivated'])->save());

        $first = $this->createTask();
        $this->createTask();

        $run = $this->runs($rule)->sole();
        $this->assertSame(AutomationRun::FAILED, $run->outcome);
        $this->assertSame('run_as_unavailable', $run->error_code);
        $this->assertStringContainsString('was switched off', $run->error);
        $this->assertArrayNotHasKey('urgent', $this->taskValues($first));
        $this->assertFalse($this->inTenant(fn () => $rule->fresh()->enabled));
        $this->inTenant(function () use ($rule) {
            $audit = AuditEntry::query()->where('action', 'core.automation.disable')->where('auditable_id', $rule->id)->sole();
            $this->assertSame('run_as_unavailable', $audit->after['reason']);
            $this->assertNull($audit->user_id);
            $this->assertSame(1, InAppNotification::query()->where('event_type', 'core.automation.failed')->where('user_id', $this->owner->id)->count());
        });

        // Switched on again, it acts as the person who enabled it.
        $this->postJson("/api/v1/automation-rules/{$rule->id}/enable", [], $this->headersFor())->assertOk();
        $this->assertSame($this->owner->id, $this->inTenant(fn () => $rule->fresh()->updated_by));
        $id = $this->createTask();
        $this->assertTrue($this->taskValues($id)['urgent']);
    }

    public function test_a_rule_whose_user_lost_a_needed_permission_is_switched_off(): void
    {
        $author = $this->userWith('admin', Scope::tenant());
        $rule = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'urgent', 'value' => true]], [], $author);
        // Still active, now only a branch manager: no automation rights for the whole tenant.
        $this->inTenant(function () use ($author) {
            RoleAssignment::query()->where('user_id', $author->id)->delete();
            $this->assign($author, $this->roles->get('branch_manager'), Scope::branch($this->branchA->id));
        });

        $this->createTask();

        $this->assertSame('run_as_unavailable', $this->runs($rule)->sole()->error_code);
        $this->assertFalse($this->inTenant(fn () => $rule->fresh()->enabled));
    }

    public function test_an_error_after_the_actions_committed_is_logged_never_retried(): void
    {
        $writes = 0;
        Event::listen(RecordChanged::class, function (RecordChanged $event) use (&$writes) {
            $writes += $event->change === 'updated' ? 1 : 0;
        });
        // A listener reacting to the rule's own change fails after its commit.
        Event::listen(RecordChanged::class, function (RecordChanged $event) {
            if ($event->cause !== null) {
                throw new RuntimeException('downstream listener broke');
            }
        });
        $rule = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'quantity', 'value' => '7']]);

        $id = $this->createTask();

        $run = $this->runs($rule)->sole();
        $this->assertSame(AutomationRun::SUCCEEDED, $run->outcome);
        $this->assertSame(1, $run->attempts, 'not retried');
        $this->assertSame('after_commit_error', $run->error_code);
        $this->assertSame(1, $writes, 'the committed action ran once');
        $this->assertSame('7', $this->taskValues($id)['quantity']);
    }

    public function test_a_failure_to_queue_one_rule_neither_reaches_the_change_nor_stops_the_others(): void
    {
        $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'urgent', 'value' => true]]);
        $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'note', 'value' => 'x']]);
        $calls = 0;
        $this->mock(RuleRunner::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('dispatch')->twice()->andReturnUsing(function () use (&$calls) {
                if (++$calls === 1) {
                    throw new RuntimeException('queue down');
                }

                return null;
            });
        });

        $this->createTask(); // no exception reaches the module

        $this->assertSame(2, $calls);
    }

    public function test_a_document_outside_the_rule_users_reach_is_skipped_and_one_that_moved_company_too(): void
    {
        // Automation for the whole tenant, but tasks only at branch A.
        $author = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Automation', ['core.automation.view', 'core.automation.edit']), Scope::tenant());
            $this->assign($user, $this->role('Branch A tasks', ['core.party.view', 'core.party.edit']), Scope::branch($this->branchA->id));

            return $user;
        });
        $rule = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'urgent', 'value' => true]], [], $author);

        $here = $this->createTask();
        $there = $this->createTask([], new DocumentScope($this->acme->id, $this->branchB->id));

        $this->assertTrue($this->taskValues($here)['urgent']);
        $this->assertArrayNotHasKey('urgent', $this->taskValues($there));
        $this->assertSame(['succeeded', 'skipped'], $this->runs($rule)->pluck('outcome')->all());
        $this->assertSame('out_of_scope', $this->runs($rule)->last()->error_code);
        $this->assertTrue($this->inTenant(fn () => $rule->fresh()->enabled), 'not switched off: the rule still works where its user reaches');

        // A company rule's run for a document that moved to another company is skipped.
        $beta = $this->inTenant(fn () => $this->company('Beta'));
        $acmeRule = $this->saveRule(['type' => 'field_changed', 'field' => 'status'], [['type' => 'update_field', 'field' => 'note', 'value' => 'x']], ['company_id' => $this->acme->id]);
        Bus::fake();
        $id = $this->quietTask();
        $this->changeTask($id, ['status' => 'closed']);
        $this->inTenant(fn () => TestDocuments::move($id, new DocumentScope($beta->id)));
        $run = $this->runs($acmeRule)->sole();
        $this->inTenant(fn () => app(RuleRunner::class)->execute($run->id));
        $this->assertSame(['skipped', 'company_changed'], $this->inTenant(fn () => [$run->fresh()->outcome, $run->fresh()->error_code]));
    }

    public function test_a_rule_never_retriggers_itself(): void
    {
        // Every change of the quantity sets it to 5: its own change would start it again.
        $rule = $this->saveRule(['type' => 'record_updated', 'fields' => ['quantity']], [['type' => 'update_field', 'field' => 'quantity', 'value' => '5']]);
        $id = $this->quietTask(['quantity' => '1']);

        $this->changeTask($id, ['quantity' => '3']);

        [$first, $second] = $this->runs($rule)->all();
        $this->assertSame(AutomationRun::SUCCEEDED, $first->outcome);
        $this->assertSame(1, $first->depth);
        $this->assertSame(AutomationRun::LOOP_BLOCKED, $second->outcome);
        $this->assertSame(2, $second->depth);
        $this->assertSame($first->chain_id, $second->chain_id);
        $this->assertSame([$rule->id], $second->chain);
        $this->assertStringContainsString('would start itself again', $second->error);
        $this->assertCount(2, $this->runs($rule));
        $this->assertSame('5', $this->taskValues($id)['quantity']);
    }

    public function test_a_chain_of_rules_stops_past_depth_three(): void
    {
        $one = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'quantity', 'value' => '1']]);
        $two = $this->saveRule(['type' => 'field_changed', 'field' => 'quantity'], [['type' => 'update_field', 'field' => 'note', 'value' => 'counted']]);
        $three = $this->saveRule(['type' => 'field_changed', 'field' => 'note'], [['type' => 'update_field', 'field' => 'urgent', 'value' => true]]);
        $four = $this->saveRule(['type' => 'field_changed', 'field' => 'urgent'], [['type' => 'update_field', 'field' => 'status', 'value' => 'closed']]);

        $id = $this->createTask();

        $this->assertSame([1, 'succeeded'], [$this->runs($one)->sole()->depth, $this->runs($one)->sole()->outcome]);
        $this->assertSame([2, 'succeeded'], [$this->runs($two)->sole()->depth, $this->runs($two)->sole()->outcome]);
        $this->assertSame([3, 'succeeded'], [$this->runs($three)->sole()->depth, $this->runs($three)->sole()->outcome]);
        $this->assertSame([4, 'loop_blocked'], [$this->runs($four)->sole()->depth, $this->runs($four)->sole()->outcome]);
        $this->assertSame([$one->id, $two->id, $three->id], $this->runs($four)->sole()->chain);
        $this->assertSame('open', $this->taskValues($id)['status']);
        $this->assertTrue($this->taskValues($id)['urgent']);
    }

    public function test_a_change_by_a_person_starts_a_new_chain(): void
    {
        $rule = $this->saveRule(['type' => 'field_changed', 'field' => 'status'], [['type' => 'update_field', 'field' => 'note', 'value' => 'seen']]);
        $id = $this->quietTask();

        $this->changeTask($id, ['status' => 'approved']);
        $this->changeTask($id, ['status' => 'closed']);

        [$a, $b] = $this->runs($rule)->all();
        $this->assertNotSame($a->chain_id, $b->chain_id);
        $this->assertSame([1, 1], [$a->depth, $b->depth]);
        $this->assertSame(['succeeded', 'succeeded'], [$a->outcome, $b->outcome]);
    }

    public function test_runs_over_the_rules_limit_per_minute_are_throttled(): void
    {
        config(['automation.rule_runs_per_minute' => 2]);
        $rule = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'urgent', 'value' => true]]);
        $other = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'quantity', 'value' => '1']]);

        foreach (range(1, 3) as $i) {
            $last = $this->createTask(['title' => "Task {$i}"]);
        }

        $this->assertSame(['succeeded', 'succeeded', 'throttled'], $this->runs($rule)->pluck('outcome')->all());
        $this->assertSame('Held back: too many rules ran in the last minute.', $this->runs($rule)->last()->error);
        $this->assertSame(0, $this->runs($rule)->last()->attempts);
        $this->assertArrayNotHasKey('urgent', $this->taskValues($last));
        $this->assertSame(['succeeded', 'succeeded', 'throttled'], $this->runs($other)->pluck('outcome')->all(), 'each rule has its own limit');
    }

    public function test_runs_over_the_tenants_limit_per_minute_are_throttled(): void
    {
        config(['automation.tenant_runs_per_minute' => 3]);
        $a = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'urgent', 'value' => true]]);
        $b = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'quantity', 'value' => '1']]);

        $this->createTask();
        $this->createTask();

        $outcomes = $this->runs()->pluck('outcome')->all();
        $this->assertSame(['succeeded', 'succeeded', 'succeeded', 'throttled'], $outcomes);
        $this->assertCount(2, $this->runs($a));
        $this->assertCount(2, $this->runs($b));
    }

    public function test_a_rule_switched_off_before_its_run_is_skipped(): void
    {
        $rule = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'urgent', 'value' => true]]);
        Bus::fake();
        $this->createTask();
        $this->inTenant(fn () => app(Rules::class)->setEnabled($rule, false, $this->owner));

        $run = $this->runs($rule)->sole();
        $this->inTenant(fn () => app(RuleRunner::class)->execute($run->id));

        $this->assertSame(AutomationRun::SKIPPED, $this->inTenant(fn () => $run->fresh()->outcome));
        $this->assertSame('The rule was switched off before it ran.', $this->inTenant(fn () => $run->fresh()->error));
    }

    public function test_the_run_log_lists_filters_shows_and_exports_runs(): void
    {
        $rule = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'urgent', 'value' => true]], ['name' => 'Flag', 'conditions' => ['field' => 'status', 'op' => 'eq', 'value' => 'open']]);
        $other = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'note', 'value' => 'x']], ['name' => 'Note']);
        $this->createTask(['status' => 'open']);
        $this->createTask(['status' => 'closed']);

        $all = $this->getJson('/api/v1/automation-runs', $this->headersFor())->assertOk();
        $this->assertCount(4, $all->json('data'));

        $mine = $this->getJson('/api/v1/automation-runs?rule='.$rule->id, $this->headersFor())->assertOk();
        $this->assertSame(['skipped', 'succeeded'], collect($mine->json('data'))->pluck('outcome')->sort()->values()->all());

        $skipped = $this->getJson('/api/v1/automation-runs?outcome=skipped', $this->headersFor())->assertOk()->json('data');
        $this->assertCount(1, $skipped);
        $this->assertSame('Flag', $skipped[0]['rule_name']);

        $id = $skipped[0]['id'];
        $this->getJson("/api/v1/automation-runs/{$id}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.outcome', 'skipped')
            ->assertJsonPath('data.rule_id', $rule->id)
            ->assertJsonPath('data.trigger_type', 'record_created')
            ->assertJsonPath('data.conditions.passed', false);

        $csv = $this->get('/api/v1/automation-runs?format=csv&rule='.$other->id, $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('Note', $csv);
        $this->assertStringContainsString('Done', $csv);
        $this->assertStringNotContainsString('Flag', $csv);

        $this->getJson('/api/v1/automation-runs?outcome=nope', $this->headersFor())->assertUnprocessable();
        $this->getJson('/api/v1/automation-runs?sort=-outcome', $this->headersFor())->assertOk();
    }

    public function test_the_run_log_shows_only_rules_the_user_may_see(): void
    {
        $beta = $this->inTenant(fn () => $this->company('Beta'));
        $betaRule = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'urgent', 'value' => true]], ['company_id' => $beta->id]);
        $acmeRule = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'note', 'value' => 'x']], ['company_id' => $this->acme->id]);
        $everywhere = $this->saveRule(['type' => 'record_created'], [['type' => 'update_field', 'field' => 'quantity', 'value' => '1']]);
        $this->createTask();
        $this->createTask([], new DocumentScope($beta->id));
        $acmeAdmin = $this->userWith('admin', Scope::company($this->acme->id));
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));

        $seen = $this->getJson('/api/v1/automation-runs', $this->headersFor($acmeAdmin))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$acmeRule->id, $everywhere->id], array_values(array_unique(array_column($seen, 'rule_id'))));
        // The rule for every company: only its run for Acme's task.
        $this->assertSame([$this->acme->id], array_column(array_filter($seen, fn ($r) => $r['rule_id'] === $everywhere->id), 'company_id'));
        $betaRunOfEverywhere = $this->runs($everywhere)->firstWhere('company_id', $beta->id);
        $this->getJson("/api/v1/automation-runs/{$betaRunOfEverywhere->id}", $this->headersFor($acmeAdmin))->assertNotFound();
        $this->getJson("/api/v1/automation-runs/{$betaRunOfEverywhere->id}", $this->headersFor())->assertOk();
        $betaRun = $this->runs($betaRule)->sole();
        $this->getJson("/api/v1/automation-runs/{$betaRun->id}", $this->headersFor($acmeAdmin))->assertNotFound();
        $this->getJson('/api/v1/automation-runs?rule='.$betaRule->id, $this->headersFor($acmeAdmin))->assertUnprocessable();

        $this->getJson('/api/v1/automation-runs', $this->headersFor($cashier))->assertForbidden();

        $other = $this->otherTenant();
        $this->getJson("/api/v1/automation-runs/{$betaRun->id}", $this->headersFor($other['user']))->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/automation-runs', $this->headersFor($other['user']))->assertOk()->json('data'));
    }
}
