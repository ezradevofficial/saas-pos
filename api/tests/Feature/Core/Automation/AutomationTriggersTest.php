<?php

namespace Tests\Feature\Core\Automation;

use App\Core\Automation\Jobs\ScanTimedTriggers;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Automation\Runtime\Reaper;
use App\Core\Automation\Runtime\Rules;
use App\Core\Automation\Runtime\TimedTriggers;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsAutomation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Workflow\Graphs;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * AUTO-01: every trigger fires the rules of the document's type, and only
 * when it should: record created, changed (any field or listed fields),
 * archived; a field changed (with from and to); a threshold crossed down
 * or up (numbers and money, one currency); a workflow stage entered or
 * left; a date (days before, in the company's time zone, once per
 * occurrence); a schedule (once per occurrence, next one computed).
 * Rules of another company or switched off never fire.
 */
class AutomationTriggersTest extends TestCase
{
    use BuildsAutomation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpAutomation();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function notified(): int
    {
        return $this->inTenant(fn () => InAppNotification::query()->where('event_type', 'core.automation.notify')->count());
    }

    public function test_record_created_runs_the_rule_and_notifies_with_the_documents_values(): void
    {
        $rule = $this->saveRule(['type' => 'record_created'], [$this->notifyOwner('New task {title}', 'Quantity {quantity}, due {due_on}, amount {amount}.')]);

        $id = $this->createTask(['quantity' => '12.5', 'due_on' => '2026-11-07', 'amount' => $this->kes(1245000)]);

        $run = $this->runs($rule)->sole();
        $this->assertSame(AutomationRun::SUCCEEDED, $run->outcome);
        $this->assertSame('record_created', $run->trigger_type);
        $this->assertSame($id, $run->document_id);
        $this->assertSame(1, $run->attempts);
        $this->assertSame(1, $run->depth);
        $this->assertEquals([['type' => 'notify', 'status' => 'done', 'result' => ['sent' => [$this->owner->id], 'skipped' => [], 'skipped_reason' => null]]], $run->actions);

        $this->inTenant(function () use ($id) {
            $note = InAppNotification::query()->where('event_type', 'core.automation.notify')->sole();
            $this->assertSame('New task Count stock', $note->subject);
            $this->assertStringContainsString('Quantity 12.5, due 7 Nov 2026, amount KES 12,450.00.', $note->body);
            $this->assertSame('/tasks/'.$id, $note->link);
        });
    }

    public function test_record_updated_fires_on_any_change_or_only_on_the_listed_fields(): void
    {
        $any = $this->saveRule(['type' => 'record_updated'], [$this->notifyOwner()]);
        $listed = $this->saveRule(['type' => 'record_updated', 'fields' => ['quantity']], [$this->notifyOwner()]);
        $id = $this->quietTask(['quantity' => '1']);

        $this->changeTask($id, ['note' => 'Checked']);
        $this->assertCount(1, $this->runs($any));
        $this->assertEquals(['change' => 'updated', 'fields' => ['note']], $this->runs($any)->first()->trigger);
        $this->assertCount(0, $this->runs($listed));

        $this->changeTask($id, ['quantity' => '2']);
        $this->assertCount(2, $this->runs($any));
        $this->assertEquals(['change' => 'updated', 'fields' => ['quantity']], $this->runs($listed)->sole()->trigger);
    }

    public function test_record_archived_fires_only_on_archiving(): void
    {
        $rule = $this->saveRule(['type' => 'record_archived'], [$this->notifyOwner()]);
        $id = $this->createTask();
        $this->changeTask($id, ['note' => 'x']);
        $this->assertCount(0, $this->runs($rule));

        $this->inTenant(fn () => $this->tasks()->archive($id, $this->owner));

        $this->assertSame('archived', $this->runs($rule)->sole()->trigger['change']);
    }

    public function test_field_changed_respects_from_and_to(): void
    {
        $approved = $this->saveRule(['type' => 'field_changed', 'field' => 'status', 'from' => 'open', 'to' => 'approved'], [$this->notifyOwner()]);
        $anyChange = $this->saveRule(['type' => 'field_changed', 'field' => 'status'], [$this->notifyOwner()]);
        $id = $this->quietTask(['status' => 'open']);

        $this->changeTask($id, ['note' => 'unrelated']);
        $this->changeTask($id, ['status' => 'closed']);
        $this->assertCount(0, $this->runs($approved));
        $this->assertCount(1, $this->runs($anyChange));

        $this->changeTask($id, ['status' => 'open']);
        $this->changeTask($id, ['status' => 'approved']);
        $this->assertEquals(['change' => 'updated', 'field' => 'status'], $this->runs($approved)->sole()->trigger);
        $this->assertCount(3, $this->runs($anyChange));
    }

    public function test_threshold_fires_when_the_value_crosses_the_level_not_while_it_stays_beyond_it(): void
    {
        $down = $this->saveRule(['type' => 'threshold', 'field' => 'quantity', 'value' => '10', 'direction' => 'down'], [$this->notifyOwner()]);
        $up = $this->saveRule(['type' => 'threshold', 'field' => 'quantity', 'value' => 10, 'direction' => 'up'], [$this->notifyOwner()]);
        $id = $this->quietTask(['quantity' => '12']);

        $this->changeTask($id, ['quantity' => '10']);   // at the level: not below yet
        $this->assertCount(0, $this->runs($down));
        $this->changeTask($id, ['quantity' => '9.5']);  // crosses down
        $this->changeTask($id, ['quantity' => '3']);    // stays below
        $this->assertCount(1, $this->runs($down));
        $this->assertEquals(['change' => 'updated', 'field' => 'quantity', 'direction' => 'down'], $this->runs($down)->first()->trigger);

        $this->assertCount(0, $this->runs($up));
        $this->changeTask($id, ['quantity' => '11']);   // crosses up
        $this->assertCount(1, $this->runs($up));
    }

    public function test_a_money_threshold_compares_within_its_currency_only(): void
    {
        $rule = $this->saveRule(['type' => 'threshold', 'field' => 'amount', 'value' => $this->kes(100000), 'direction' => 'down'], [$this->notifyOwner()]);
        $id = $this->quietTask(['amount' => $this->kes(150000)]);

        $this->changeTask($id, ['amount' => ['amount_minor' => '500', 'currency' => 'USD']]);
        $this->assertCount(0, $this->runs($rule), 'another currency never crosses');

        $this->changeTask($id, ['amount' => $this->kes(150000)]);
        $this->changeTask($id, ['amount' => $this->kes(99999)]);
        $this->assertCount(1, $this->runs($rule));
    }

    public function test_stage_entered_and_left_fire_from_workflow_moves(): void
    {
        $this->publishFlow(Graphs::linear(['review', 'check']));
        $entered = $this->inTenant(fn () => app(Rules::class)->create([
            'name' => 'Entered review', 'document_type' => TestRequestType::KEY, 'enabled' => true,
            'trigger' => ['type' => 'stage_entered', 'stage' => 'review'],
            'actions' => [['type' => 'notify', 'to' => ['role:owner'], 'subject' => 'In review', 'message' => 'A request is in review.']],
        ], $this->owner));
        $left = $this->inTenant(fn () => app(Rules::class)->create([
            'name' => 'Left any', 'document_type' => TestRequestType::KEY, 'enabled' => true,
            'trigger' => ['type' => 'stage_left', 'how' => 'completed'],
            'actions' => [['type' => 'notify', 'to' => ['role:owner'], 'subject' => 'Moved', 'message' => 'A request moved on.']],
        ], $this->owner));

        $id = $this->document(['note' => 'Chairs']);
        $workflow = $this->start($id);
        $this->assertEquals(['stage' => 'review'], $this->runs($entered)->sole()->trigger);
        $this->assertCount(0, $this->runs($left));

        $this->inTenant(fn () => $this->engine()->move($workflow, $this->owner));
        $this->assertCount(1, $this->runs($entered), 'entering "check" is not "review"');
        $this->assertEquals(['stage' => 'review', 'how' => 'completed'], $this->runs($left)->sole()->trigger);
        $this->assertSame(AutomationRun::SUCCEEDED, $this->runs($left)->sole()->outcome);
    }

    public function test_a_date_trigger_runs_once_per_document_and_day_after_the_local_scan_hour(): void
    {
        $rule = $this->saveRule(['type' => 'date', 'field' => 'due_on', 'days' => 3, 'when' => 'before'], [$this->notifyOwner()]);
        // Nairobi is UTC+3: 2026-10-08 03:30 UTC is 06:30 there; the target day is 2026-10-11.
        $due = $this->quietTask(['due_on' => '2026-10-11']);
        $dueAsInstant = $this->quietTask(['due_on' => '2026-10-10T22:30:00Z']); // 11 Oct 01:30 in Nairobi
        $this->quietTask(['due_on' => '2026-10-12']);
        $otherCompany = $this->inTenant(fn () => $this->company('Beta'));
        $this->quietTask(['due_on' => '2026-10-11'], new DocumentScope($otherCompany->id));
        $companyRule = $this->saveRule(['type' => 'date', 'field' => 'due_on', 'days' => 3, 'when' => 'before'], [$this->notifyOwner()], ['company_id' => $this->acme->id]);

        $scan = fn (string $at) => $this->inTenant(fn () => (new ScanTimedTriggers($this->owner->tenant_id, ScanTimedTriggers::DATES, $at))->handle(app(TimedTriggers::class), app(Reaper::class)));

        $scan('2026-10-08T02:30:00Z'); // 05:30 in Nairobi: before the scan hour
        $this->assertCount(0, $this->runs($rule));

        $scan('2026-10-08T03:30:00Z');
        $scan('2026-10-08T09:30:00Z'); // the same day again: nothing new

        $runs = $this->runs($rule);
        $this->assertCount(3, $runs, 'both companies\' tasks due on the 11th');
        $this->assertContains($due, $runs->pluck('document_id'));
        $this->assertContains($dueAsInstant, $runs->pluck('document_id'));
        $this->assertEquals(['field' => 'due_on', 'date' => '2026-10-11'], $runs->first()->trigger);
        $this->assertTrue($runs->every(fn (AutomationRun $r) => $r->outcome === AutomationRun::SUCCEEDED));
        $this->assertCount(2, $this->runs($companyRule), 'a company rule only scans its company');
    }

    public function test_a_schedule_runs_once_per_occurrence_in_the_company_time_zone(): void
    {
        CarbonImmutable::setTestNow('2026-10-08T03:00:00Z'); // Thursday 06:00 in Nairobi
        $rule = $this->saveRule(['type' => 'schedule', 'every' => 'week', 'days' => ['thu', 'mon'], 'time' => '08:00'], [$this->notifyOwner('Weekly check', 'Count the stock.')]);
        $this->assertSame('2026-10-08T05:00:00+00:00', $rule->next_run_at->toIso8601String());

        $scan = fn (string $at) => $this->inTenant(fn () => app(TimedTriggers::class)->schedules(CarbonImmutable::parse($at)));

        $this->assertSame(0, $scan('2026-10-08T04:59:00Z'));
        $this->assertSame(1, $scan('2026-10-08T05:00:30Z'));
        $this->assertSame(0, $scan('2026-10-08T05:01:00Z'));

        $run = $this->runs($rule)->sole();
        $this->assertNull($run->document_id);
        $this->assertSame(['occurrence' => '2026-10-08T05:00:00Z'], $run->trigger);
        $this->assertSame(AutomationRun::SUCCEEDED, $run->outcome);
        $this->assertSame(1, $this->notified());
        // Next: Monday 12 October 08:00 Nairobi.
        $this->assertSame('2026-10-12T05:00:00+00:00', $this->inTenant(fn () => $rule->fresh()->next_run_at->toIso8601String()));
    }

    public function test_a_throttled_schedule_occurrence_is_tried_again_at_the_next_scan(): void
    {
        CarbonImmutable::setTestNow('2026-10-08T03:00:00Z');
        $rule = $this->saveRule(['type' => 'schedule', 'every' => 'day', 'time' => '08:00'], [$this->notifyOwner('Daily', 'Count the stock.')]);
        $scan = fn (string $at) => $this->inTenant(fn () => app(TimedTriggers::class)->schedules(CarbonImmutable::parse($at)));
        // The rule used its one run this minute.
        config(['automation.rule_runs_per_minute' => 1]);
        RateLimiter::hit('automation:rule:'.$rule->id, 60);

        $this->assertSame(0, $scan('2026-10-08T05:00:10Z'));
        $this->assertSame('throttled', $this->runs($rule)->sole()->outcome);
        $this->assertSame('2026-10-08T05:00:00+00:00', $this->inTenant(fn () => $rule->fresh()->next_run_at->toIso8601String()), 'not moved on');

        RateLimiter::clear('automation:rule:'.$rule->id);
        $this->assertSame(1, $scan('2026-10-08T05:01:10Z'));
        $this->assertSame(['throttled', 'succeeded'], $this->runs($rule)->pluck('outcome')->all());
        $this->assertSame('2026-10-09T05:00:00+00:00', $this->inTenant(fn () => $rule->fresh()->next_run_at->toIso8601String()));
    }

    public function test_a_date_scan_catches_up_the_previous_day_once(): void
    {
        CarbonImmutable::setTestNow('2026-10-01T00:00:00Z');
        $rule = $this->saveRule(['type' => 'date', 'field' => 'due_on', 'days' => 3, 'when' => 'before'], [$this->notifyOwner()]);
        CarbonImmutable::setTestNow();
        // Due on the 10th: yesterday's (7 Oct) occurrence was missed.
        $missed = $this->quietTask(['due_on' => '2026-10-10']);
        $today = $this->quietTask(['due_on' => '2026-10-11']);
        $scan = fn (string $at) => $this->inTenant(fn () => app(TimedTriggers::class)->dates(CarbonImmutable::parse($at)));

        $scan('2026-10-08T01:00:00Z'); // 04:00 in Nairobi: today's hour has not come; yesterday is caught up
        $this->assertSame([$missed], $this->runs($rule)->pluck('document_id')->all());

        $scan('2026-10-08T04:00:00Z');
        $scan('2026-10-08T05:00:00Z');
        $this->assertEqualsCanonicalizing([$missed, $today], $this->runs($rule)->pluck('document_id')->all(), 'each once');
    }

    public function test_scans_have_their_own_budget_and_never_starve_live_triggers(): void
    {
        config(['automation.tenant_runs_per_minute' => 1]);
        $live = $this->saveRule(['type' => 'record_created'], [$this->notifyOwner()]);
        $date = $this->saveRule(['type' => 'date', 'field' => 'due_on', 'days' => 0, 'when' => 'on'], [$this->notifyOwner()]);
        $this->quietTask(['due_on' => '2026-10-08']);
        $this->quietTask(['due_on' => '2026-10-08']);

        $this->inTenant(fn () => app(TimedTriggers::class)->dates(CarbonImmutable::parse('2026-10-08T05:00:00Z')));
        $this->assertSame(['succeeded', 'throttled'], $this->runs($date)->pluck('outcome')->all(), 'the scan used up its own budget');

        $this->createTask();
        $this->assertSame('succeeded', $this->runs($live)->sole()->outcome, 'a live trigger still runs');
    }

    public function test_rules_that_are_off_archived_of_another_company_or_type_never_fire(): void
    {
        $off = $this->saveRule(['type' => 'record_created'], [$this->notifyOwner()], ['enabled' => false]);
        $archived = $this->saveRule(['type' => 'record_created'], [$this->notifyOwner()]);
        $this->inTenant(fn () => app(Rules::class)->archive($archived, $this->owner));
        $otherCompany = $this->inTenant(fn () => $this->company('Beta'));
        $elsewhere = $this->saveRule(['type' => 'record_created'], [$this->notifyOwner()], ['company_id' => $otherCompany->id]);
        $here = $this->saveRule(['type' => 'record_created'], [$this->notifyOwner()], ['company_id' => $this->acme->id]);

        $this->createTask();
        $this->document(); // a request: another type

        $this->assertCount(0, $this->runs($off));
        $this->assertCount(0, $this->runs($archived));
        $this->assertCount(0, $this->runs($elsewhere));
        $this->assertCount(1, $this->runs($here));
    }

    public function test_another_tenants_changes_never_fire_this_tenants_rules(): void
    {
        $rule = $this->saveRule(['type' => 'record_created'], [$this->notifyOwner()]);
        $other = $this->otherTenant();

        $this->asTenant($other['user']->tenant_id, fn () => $this->tasks()->create(['title' => 'Theirs'], new DocumentScope($other['company']->id), $other['user']));

        $this->assertCount(0, $this->runs($rule));
        $this->assertSame(0, $this->asTenant($other['user']->tenant_id, fn () => AutomationRun::query()->count()));
    }
}
