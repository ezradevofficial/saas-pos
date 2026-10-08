<?php

namespace Tests\Feature\Core\Automation;

use App\Core\Audit\AuditEntry;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Automation\Runtime\Rules;
use App\Core\Automation\Runtime\TimedTriggers;
use App\Core\Automation\Templates\RuleTemplates;
use App\Core\Rbac\Scope;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsAutomation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Automation\TestTaskType;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * AUTO-07: the library of ready-made rules lists the templates usable
 * with each document type and their parameters; "use template" saves the
 * built rule switched off (audited with the template), validated like any
 * rule; the generic "remind the owner before a date" works end to end.
 */
class AutomationTemplatesTest extends TestCase
{
    use BuildsAutomation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpAutomation();
    }

    public function test_the_library_lists_templates_with_the_types_they_fit(): void
    {
        $all = collect($this->getJson('/api/v1/automation-templates', $this->headersFor())->assertOk()->json('data'))->keyBy('key');

        $this->assertSame(['core.alert_below_level', 'core.remind_before_date'], $all->keys()->all());
        $remind = $all['core.remind_before_date'];
        $this->assertSame('Remind the owner before a date', $remind['label']);
        $this->assertSame([TestTaskType::KEY], array_column($remind['document_types'], 'key'), 'only types searchable by date');
        $this->assertSame([
            ['name' => 'field', 'kind' => 'field', 'fields' => ['due_on'], 'default' => 'due_on'],
            ['name' => 'days', 'kind' => 'days', 'default' => 7],
            ['name' => 'to', 'kind' => 'recipients', 'default' => ['field:owner']],
        ], $remind['document_types'][0]['parameters']);
        $this->assertContains(TestRequestType::KEY, array_column($all['core.alert_below_level']['document_types'], 'key'));

        $forRequests = $this->getJson('/api/v1/automation-templates?type='.TestRequestType::KEY, $this->headersFor())->assertOk()->json('data');
        $this->assertSame(['core.alert_below_level'], array_column($forRequests, 'key'));

        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->getJson('/api/v1/automation-templates', $this->headersFor($cashier))->assertForbidden();
    }

    public function test_using_a_template_saves_a_disabled_rule_that_reminds_the_owner_before_the_date(): void
    {
        $response = $this->postJson('/api/v1/automation-templates/use', [
            'template' => 'core.remind_before_date',
            'document_type' => TestTaskType::KEY,
            'company_id' => $this->acme->id,
            'params' => ['days' => 3],
        ], $this->headersFor())->assertCreated();

        $response->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.name', 'Reminder 3 days before Updated')
            ->assertJsonPath('data.company_id', $this->acme->id)
            ->assertJsonPath('data.actions.0.to', ['field:owner'])
            ->assertJsonPath('data.actions.0.message', 'This is a reminder that Updated is on {due_on}.');
        $this->assertEquals(['type' => 'date', 'field' => 'due_on', 'days' => 3, 'when' => 'before'], $response->json('data.trigger'));
        $id = $response->json('data.id');
        $this->assertSame('core.remind_before_date', $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.automation.create')->sole()->after['template']));

        // Switched on, it reminds the task's owner three days ahead.
        $this->postJson("/api/v1/automation-rules/{$id}/enable", [], $this->headersFor())->assertOk();
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->quietTask(['due_on' => '2026-10-11', 'owner' => $manager->id], new DocumentScope($this->acme->id, $this->branchA->id));
        $this->inTenant(fn () => app(TimedTriggers::class)->dates(CarbonImmutable::parse('2026-10-08T04:00:00Z')));

        $run = $this->inTenant(fn () => AutomationRun::query()->sole());
        $this->assertSame(AutomationRun::SUCCEEDED, $run->outcome);
        $this->assertSame([$manager->id], $run->actions[0]['result']['sent']);
    }

    public function test_a_template_is_refused_for_a_type_it_does_not_fit_or_with_bad_parameters(): void
    {
        $this->postJson('/api/v1/automation-templates/use', ['template' => 'core.remind_before_date', 'document_type' => TestRequestType::KEY], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['template']);
        $this->postJson('/api/v1/automation-templates/use', ['template' => 'core.nope', 'document_type' => TestTaskType::KEY], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['template']);
        $this->postJson('/api/v1/automation-templates/use', ['template' => 'core.alert_below_level', 'document_type' => TestTaskType::KEY, 'params' => ['field' => 'title', 'value' => '1']], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['trigger']);
        $this->postJson('/api/v1/automation-templates/use', ['template' => 'core.alert_below_level', 'document_type' => TestTaskType::KEY, 'params' => ['field' => 'quantity', 'value' => '10']], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.trigger.direction', 'down');

        $auditor = $this->userWith('read_only_auditor', Scope::tenant());
        $this->postJson('/api/v1/automation-templates/use', ['template' => 'core.alert_below_level', 'document_type' => TestTaskType::KEY, 'params' => ['value' => '10']], $this->headersFor($auditor))
            ->assertForbidden();
    }

    public function test_modules_register_templates_and_inactive_modules_hide_theirs(): void
    {
        $templates = app(RuleTemplates::class);
        $this->assertNotNull($templates->find('core.remind_before_date'));
        $this->assertNull($templates->find('pos.reorder_alert'), 'unknown');
        $this->assertSame(2, count($templates->for($this->tasks())));
        $this->assertInstanceOf(Rules::class, app(Rules::class));
    }
}
