<?php

namespace Tests\Feature\Core\Automation;

use App\Core\Automation\Webhooks\HostResolver;
use App\Core\Rbac\Scope;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsAutomation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Automation\FakeHostResolver;
use Tests\Support\Automation\TestTaskType;
use Tests\Support\Workflow\TestDocuments;
use Tests\Support\Workflow\TestOrderType;
use Tests\TestCase;

/**
 * AUTO-04 test mode: a saved or unsaved rule run against sample values or
 * a real document the user may see says whether it would fire, how its
 * conditions come out (with reasons) and what each action would do,
 * without any side effect: no database write (no run, no audit, no
 * notification), no HTTP, no DNS lookup, no change to the document.
 */
class AutomationTestModeTest extends TestCase
{
    use BuildsAutomation, RefreshTenantDatabase;

    private FakeHostResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake();
        $this->setUpAutomation();
        $this->dns = new FakeHostResolver(['hooks.example.com' => [['93.184.216.34']]]);
        $this->app->instance(HostResolver::class, $this->dns);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, int> rows per table the test must leave untouched */
    private function counts(): array
    {
        return $this->inTenant(fn () => collect(['automation_runs', 'automation_rules', 'notifications', 'notification_deliveries', 'audit_logs'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all());
    }

    private function everyAction(string $managerId): array
    {
        return [
            ['type' => 'update_field', 'field' => 'status', 'value' => 'approved'],
            ['type' => 'assign_user', 'field' => 'owner', 'user' => $managerId],
            ['type' => 'notify', 'to' => ['role:branch_manager', 'field:owner'], 'subject' => 'Big task {title}', 'message' => 'Worth {amount}.'],
            ['type' => 'create_document', 'target' => TestOrderType::KEY, 'mapping' => ['amount' => 'amount']],
            ['type' => 'set_credit_hold', 'hold' => true, 'reason' => 'Over limit'],
            ['type' => 'change_stage', 'mode' => 'move'],
            ['type' => 'webhook', 'url' => 'https://hooks.example.com/in?token=secret'],
        ];
    }

    public function test_a_saved_rule_against_a_real_document_describes_every_action_and_changes_nothing(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $rule = $this->saveRule(['type' => 'field_changed', 'field' => 'status', 'to' => 'approved'], $this->everyAction($manager->id), [
            'conditions' => ['field' => 'amount', 'op' => 'gt', 'value' => $this->kes(100000)],
        ]);
        $id = $this->quietTask(['amount' => $this->kes(500000), 'status' => 'open']);
        $headers = $this->headersFor(); // signing in is audited: before counting
        $before = $this->counts();
        $values = $this->taskValues($id);

        $response = $this->postJson("/api/v1/automation-rules/{$rule->id}/test", [
            'document_id' => $id,
            'old_values' => ['status' => 'open'],
        ], $headers)->assertOk();

        $this->assertSame($before, $this->counts());
        $this->assertSame($values, $this->taskValues($id));
        $this->assertSame([], $this->inTenant(fn () => TestDocuments::ofType(TestOrderType::KEY)));
        Http::assertNothingSent();
        $this->assertSame([], $this->dns->lookups);

        $data = $response->json('data');
        $this->assertSame('field_changed', $data['trigger']['type']);
        // The document is still open, so "changed to approved" does not hold; the actions are described anyway.
        $this->assertFalse($data['trigger']['matches']);
        $this->assertTrue($data['conditions']['passed']);
        $this->assertFalse($data['would_run']);
        $this->assertSame(['update_field', 'assign_user', 'notify', 'create_document', 'set_credit_hold', 'change_stage', 'webhook'], array_column($data['actions'], 'type'));
        $descriptions = array_column($data['actions'], 'description');
        $this->assertSame('Would set Status to approved.', $descriptions[0]);
        $this->assertSame("Would assign {$manager->name} as Rule.", $descriptions[1]);
        $this->assertStringContainsString('Would notify 1 people', $descriptions[2]);
        $this->assertStringContainsString('“Big task Count stock”. Worth KES 5,000.00.', $descriptions[2]);
        $this->assertStringContainsString('Would create a draft', $descriptions[3]);
        $this->assertStringContainsString('KES 5,000.00', $descriptions[3]);
        $this->assertSame('Would put the customer on credit hold: Over limit', $descriptions[4]);
        $this->assertSame('Would complete the current stage and move the document on.', $descriptions[5]);
        $this->assertSame('Would send the document, signed, to https://hooks.example.com/in.', $descriptions[6]);
        $this->assertStringNotContainsString($rule->revealedSecret, $response->getContent());
    }

    public function test_sample_values_check_the_trigger_and_explain_failed_conditions(): void
    {
        $rule = $this->saveRule(['type' => 'field_changed', 'field' => 'status', 'from' => 'open', 'to' => 'approved'], [$this->notifyOwner()], [
            'conditions' => ['all' => [['field' => 'amount', 'op' => 'gt', 'value' => $this->kes(100000)]]],
        ]);
        $url = "/api/v1/automation-rules/{$rule->id}/test";

        $fires = $this->postJson($url, ['values' => ['status' => 'approved', 'amount' => $this->kes(500000)], 'old_values' => ['status' => 'open']], $this->headersFor())->assertOk();
        $this->assertTrue($fires->json('data.trigger.matches'));
        $this->assertTrue($fires->json('data.would_run'));

        $wrongChange = $this->postJson($url, ['values' => ['status' => 'closed', 'amount' => $this->kes(500000)], 'old_values' => ['status' => 'open']], $this->headersFor())->assertOk();
        $this->assertFalse($wrongChange->json('data.trigger.matches'));
        $this->assertFalse($wrongChange->json('data.would_run'));

        $small = $this->postJson($url, ['values' => ['status' => 'approved', 'amount' => $this->kes(5000)], 'old_values' => ['status' => 'open']], $this->headersFor())->assertOk();
        $this->assertFalse($small->json('data.conditions.passed'));
        $this->assertSame(['Status must be more than KES 1,000.00; it is KES 50.00.'], str_replace('Trigger', 'Status', $small->json('data.conditions.reasons')));
        $this->assertFalse($small->json('data.would_run'));
        $this->assertSame(0, $this->runs()->count());
    }

    public function test_a_date_rule_says_whether_the_document_is_due_today_and_a_schedule_its_next_run(): void
    {
        CarbonImmutable::setTestNow('2026-10-08T07:00:00Z');
        $date = $this->saveRule(['type' => 'date', 'field' => 'due_on', 'days' => 3, 'when' => 'before'], [$this->notifyOwner()]);
        $url = "/api/v1/automation-rules/{$date->id}/test";

        $this->postJson($url, ['values' => ['due_on' => '2026-10-11']], $this->headersFor())->assertOk()
            ->assertJsonPath('data.trigger.matches', true)->assertJsonPath('data.trigger.details.date', '2026-10-11');
        $this->postJson($url, ['values' => ['due_on' => '2026-10-12']], $this->headersFor())->assertOk()
            ->assertJsonPath('data.trigger.matches', false);

        $schedule = $this->saveRule(['type' => 'schedule', 'every' => 'day', 'time' => '08:00'], [$this->notifyOwner('Daily', 'Count the stock.')]);
        $this->postJson("/api/v1/automation-rules/{$schedule->id}/test", [], $this->headersFor())->assertOk()
            ->assertJsonPath('data.trigger.next_run_at', '2026-10-09T05:00:00Z')
            ->assertJsonPath('data.conditions', null)
            ->assertJsonPath('data.would_run', true);
    }

    public function test_an_unsaved_rule_is_validated_and_tested_without_saving(): void
    {
        $body = [
            'document_type' => TestTaskType::KEY,
            'trigger' => ['type' => 'record_created'],
            'conditions' => ['field' => 'urgent', 'op' => 'eq', 'value' => true],
            'actions' => [['type' => 'update_field', 'field' => 'note', 'value' => 'flagged'], ['type' => 'webhook', 'url' => 'https://hooks.example.com/x']],
            'values' => ['urgent' => true],
        ];
        $headers = $this->headersFor(); // signing in is audited: before counting
        $before = $this->counts();

        $this->postJson('/api/v1/automation-rules/test', $body, $headers)->assertOk()
            ->assertJsonPath('data.trigger.matches', true)
            ->assertJsonPath('data.would_run', true)
            ->assertJsonPath('data.actions.0.description', 'Would set Version to flagged.');
        $this->assertSame($before, $this->counts());
        Http::assertNothingSent();

        $this->postJson('/api/v1/automation-rules/test', [...$body, 'actions' => [['type' => 'update_field', 'field' => 'title', 'value' => 'x']]], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
        $this->postJson('/api/v1/automation-rules/test', [...$body, 'trigger' => ['type' => 'whenever']], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['trigger']);
    }

    public function test_a_document_the_user_cannot_see_or_of_another_tenant_is_not_found(): void
    {
        $rule = $this->saveRule(['type' => 'record_created'], [$this->notifyOwner()]);
        $theirs = $this->quietTask([], new DocumentScope($this->acme->id, $this->branchB->id));
        $managerA = $this->userWith('admin', Scope::branch($this->branchA->id));
        $url = "/api/v1/automation-rules/{$rule->id}/test";

        $this->postJson($url, ['document_id' => $theirs], $this->headersFor())->assertOk();
        // An admin of branch A sees the rule (it is for every company) but not branch B's task.
        $this->postJson($url, ['document_id' => $theirs], $this->headersFor($managerA))->assertUnprocessable()->assertJsonValidationErrors(['document_id']);

        $other = $this->otherTenant();
        $foreign = $this->asTenant($other['user']->tenant_id, fn () => TestDocuments::create(TestTaskType::KEY, ['title' => 'x'], new DocumentScope($other['company']->id)));
        $this->postJson($url, ['document_id' => $foreign], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['document_id']);
        $this->postJson("/api/v1/automation-rules/{$rule->id}/test", [], $this->headersFor($other['user']))->assertNotFound();
    }

    public function test_reading_a_rule_is_enough_to_test_it_but_testing_an_unsaved_one_needs_edit(): void
    {
        $rule = $this->saveRule(['type' => 'record_created'], [$this->notifyOwner()]);
        $auditor = $this->userWith('read_only_auditor', Scope::tenant());
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));

        $this->postJson("/api/v1/automation-rules/{$rule->id}/test", ['values' => []], $this->headersFor($auditor))->assertOk();
        $this->postJson('/api/v1/automation-rules/test', [
            'document_type' => TestTaskType::KEY, 'trigger' => ['type' => 'record_created'], 'actions' => [$this->notifyOwner()],
        ], $this->headersFor($auditor))->assertForbidden();
        $this->postJson("/api/v1/automation-rules/{$rule->id}/test", [], $this->headersFor($cashier))->assertNotFound();
    }
}
