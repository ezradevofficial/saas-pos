<?php

namespace Tests\Feature\Core\Automation;

use App\Core\Automation\Models\AutomationRule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsAutomation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Automation\TestTaskType;
use Tests\Support\Workflow\TestOrderType;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * AUTO-01, AUTO-03: the catalogue offers only triggers and actions that
 * can work with a type, and saving refuses the others (422): record
 * triggers need a type that raises record events; "change stage" needs a
 * flow with a `stage` node (approval nodes are decided by approvers) and
 * a named stage must be one; "create document" needs a type that creates
 * drafts. The catalogue lists the flow's stage and approval nodes.
 */
class AutomationCapabilitiesTest extends TestCase
{
    use BuildsAutomation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpAutomation();
    }

    private function notify(): array
    {
        return ['type' => 'notify', 'to' => ['role:admin'], 'subject' => 's', 'message' => 'm'];
    }

    private function saveRequestRule(array $trigger, array $actions): TestResponse
    {
        return $this->postJson('/api/v1/automation-rules', [
            'name' => 'Requests', 'document_type' => TestRequestType::KEY, 'trigger' => $trigger, 'actions' => $actions,
        ], $this->headersFor());
    }

    private function approvalFlow(): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'review', 'type' => 'stage', 'name' => 'Review'],
                ['id' => 'approve', 'type' => 'approval', 'name' => 'Manager approves', 'approval' => ['approver' => ['type' => 'user', 'user_id' => $this->owner->id]]],
                ['id' => 'end', 'type' => 'end', 'outcome' => 'approved'],
            ],
            'edges' => [['from' => 'start', 'to' => 'review'], ['from' => 'review', 'to' => 'approve'], ['from' => 'approve', 'to' => 'end', 'branch' => 'approved']],
        ];
    }

    private function catalogueType(string $key): array
    {
        return collect($this->getJson('/api/v1/automation/catalogue', $this->headersFor())->assertOk()->json('data'))->firstWhere('key', $key);
    }

    public function test_record_triggers_are_refused_for_a_type_that_raises_no_record_events(): void
    {
        foreach (['record_created', 'record_archived', 'record_updated'] as $trigger) {
            $this->saveRequestRule(['type' => $trigger], [$this->notify()])->assertUnprocessable()->assertJsonValidationErrors(['trigger']);
        }
        $this->saveRequestRule(['type' => 'field_changed', 'field' => 'note'], [$this->notify()])->assertUnprocessable()->assertJsonValidationErrors(['trigger']);
        $this->saveRequestRule(['type' => 'stage_entered'], [$this->notify()])->assertCreated();

        $credit = $this->catalogueType('core.credit_limit_change');
        $this->assertFalse($credit['raises_record_events']);
        $this->assertEmpty(array_intersect(['record_created', 'record_updated', 'record_archived', 'field_changed', 'threshold'], $credit['triggers']));
        $this->postJson('/api/v1/automation-rules', [
            'name' => 'Limits', 'document_type' => 'core.credit_limit_change', 'trigger' => ['type' => 'record_created'], 'actions' => [$this->notify()],
        ], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors(['trigger']);
    }

    public function test_change_stage_needs_a_flow_stage_and_the_catalogue_lists_stage_and_approval_nodes(): void
    {
        $this->saveRequestRule(['type' => 'stage_entered'], [['type' => 'change_stage', 'mode' => 'move']])
            ->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
        $this->assertSame([], $this->catalogueType(TestRequestType::KEY)['stages']);

        $this->publishFlow($this->approvalFlow());

        $type = $this->catalogueType(TestRequestType::KEY);
        $this->assertSame([
            ['id' => 'review', 'name' => 'Review', 'kind' => 'stage', 'company_id' => null],
            ['id' => 'approve', 'name' => 'Manager approves', 'kind' => 'approval', 'company_id' => null],
        ], $type['stages']);
        $this->assertContains('change_stage', $type['actions']);

        // An approval is decided by its approvers, never moved by a rule.
        $this->saveRequestRule(['type' => 'stage_entered', 'stage' => 'approve'], [['type' => 'change_stage', 'mode' => 'move', 'stage' => 'approve']])
            ->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
        $this->saveRequestRule(['type' => 'stage_entered', 'stage' => 'approve'], [['type' => 'change_stage', 'mode' => 'move', 'stage' => 'review']])->assertCreated();
        $this->saveRequestRule(['type' => 'stage_left'], [['type' => 'change_stage', 'mode' => 'move']])->assertCreated();
        $this->assertSame(2, $this->inTenant(fn () => AutomationRule::query()->count()));
    }

    public function test_a_flow_with_only_approvals_lists_them_but_offers_no_change_stage(): void
    {
        $flow = $this->approvalFlow();
        $flow['nodes'] = array_values(array_filter($flow['nodes'], fn (array $n) => $n['id'] !== 'review'));
        $flow['edges'] = [['from' => 'start', 'to' => 'approve'], ['from' => 'approve', 'to' => 'end', 'branch' => 'approved']];
        $this->publishFlow($flow);

        $type = $this->catalogueType(TestRequestType::KEY);
        $this->assertSame(['approve'], array_column($type['stages'], 'id'));
        $this->assertNotContains('change_stage', $type['actions']);
    }

    public function test_create_document_is_offered_only_while_some_type_creates_drafts(): void
    {
        $catalogue = $this->getJson('/api/v1/automation/catalogue', $this->headersFor())->assertOk();
        $this->assertEqualsCanonicalizing([TestOrderType::KEY, TestTaskType::KEY], $catalogue->json('meta.create_targets'));
        $this->assertContains('create_document', $this->catalogueType(TestRequestType::KEY)['actions']);

        // A registry with no type that creates drafts.
        $registry = new DocumentTypeRegistry($this->app, $this->app->make(ModuleRegistry::class));
        $registry->register(TestRequestType::class);
        $this->app->instance(DocumentTypeRegistry::class, $registry);

        $catalogue = $this->getJson('/api/v1/automation/catalogue', $this->headersFor())->assertOk();
        $this->assertSame([], $catalogue->json('meta.create_targets'));
        $this->assertNotContains('create_document', $catalogue->json('data.0.actions'));
    }
}
