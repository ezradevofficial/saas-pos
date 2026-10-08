<?php

namespace Tests\Feature\Core\Automation;

use App\Core\Automation\Models\AutomationRun;
use App\Core\Automation\Webhooks\HostResolver;
use App\Core\Identity\Models\User;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsAutomation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Automation\FakeHostResolver;
use Tests\Support\Automation\TestTaskType;
use Tests\TestCase;

/**
 * RBAC-05 for automation: a field the rule's user may not see (field rules
 * on the document type) never leaves through the rule: webhook payloads
 * and notification placeholders leave it out, test mode shows neither its
 * value nor a reason about it; and a rule may not trigger on, test or copy
 * a field hidden from its author (422, like hidden sorts and filters).
 */
class AutomationFieldRulesTest extends TestCase
{
    use BuildsAutomation, RefreshTenantDatabase;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->setUpAutomation();
        $this->app->instance(HostResolver::class, new FakeHostResolver(['hooks.example.com' => [['93.184.216.34']]]));

        // A clerk who automates tasks but may not see their amount.
        $this->clerk = $this->inTenant(function () {
            $user = $this->colleague($this->owner, ['name' => 'Clerk']);
            $role = $this->role('Automation clerk', ['core.automation.view', 'core.automation.edit', 'core.party.view', 'core.party.edit']);
            FieldRule::create(['role_id' => $role->id, 'resource' => TestTaskType::KEY, 'field' => 'amount', 'mode' => FieldRule::HIDDEN]);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });
    }

    private function body(array $overrides = []): array
    {
        return [
            'name' => 'Clerk rule', 'document_type' => TestTaskType::KEY,
            'trigger' => ['type' => 'record_created'],
            'actions' => [['type' => 'notify', 'to' => ["user:{$this->clerk->id}"], 'subject' => 'Task {title}', 'message' => 'Worth {amount}.']],
            ...$overrides,
        ];
    }

    public function test_rules_reading_a_hidden_field_are_refused_at_save(): void
    {
        $post = fn (array $overrides) => $this->postJson('/api/v1/automation-rules', $this->body($overrides), $this->headersFor($this->clerk));

        $post(['conditions' => ['field' => 'amount', 'op' => 'gt', 'value' => $this->kes(1)]])
            ->assertUnprocessable()->assertJsonValidationErrors(['conditions']);
        $post(['conditions' => ['any' => [['field' => 'quantity', 'op' => 'gt', 'value' => '1'], ['field' => 'amount', 'op' => 'empty']]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['conditions']);
        $post(['trigger' => ['type' => 'threshold', 'field' => 'amount', 'value' => $this->kes(1), 'direction' => 'down']])
            ->assertUnprocessable()->assertJsonValidationErrors(['trigger']);
        $post(['trigger' => ['type' => 'record_updated', 'fields' => ['title', 'amount']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['trigger']);
        $post(['actions' => [['type' => 'create_document', 'target' => 'core.test_order', 'mapping' => ['amount' => 'amount']]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);

        // Visible fields are fine; the owner (no field rules) may use the amount.
        $post(['conditions' => ['field' => 'quantity', 'op' => 'gt', 'value' => '1']])->assertCreated();
        $this->postJson('/api/v1/automation-rules', $this->body(['conditions' => ['field' => 'amount', 'op' => 'gt', 'value' => $this->kes(1)]]), $this->headersFor())->assertCreated();
    }

    public function test_webhooks_and_notifications_leave_out_fields_hidden_from_the_rules_user(): void
    {
        $rule = $this->saveRule(['type' => 'record_created'], [
            ['type' => 'webhook', 'url' => 'https://hooks.example.com/in'],
            ['type' => 'notify', 'to' => ["user:{$this->clerk->id}"], 'subject' => 'Task {title}', 'message' => 'Worth {amount}, {quantity} units.'],
        ], by: $this->clerk);

        $this->createTask(['amount' => $this->kes(987654), 'quantity' => '4']);

        $this->assertSame(AutomationRun::SUCCEEDED, $this->runs($rule)->sole()->outcome);
        Http::assertSent(function (Request $request) {
            $fields = json_decode($request->body(), true)['fields'];

            return ! array_key_exists('amount', $fields) && $fields['quantity'] === '4' && $fields['title'] === 'Count stock';
        });
        $this->inTenant(function () {
            $body = InAppNotification::query()->where('event_type', 'core.automation.notify')->sole()->body;
            $this->assertStringContainsString('Worth , 4 units.', $body);
            $this->assertStringNotContainsString('9,876.54', $body);
        });

        // The same rule acting as the owner sends the amount.
        $this->inTenant(fn () => $rule->forceFill(['updated_by' => $this->owner->id])->save());
        $this->createTask(['amount' => $this->kes(987654)]);
        // Key order is the stored jsonb's, so compare as a map.
        Http::assertSent(fn (Request $request) => (json_decode($request->body(), true)['fields']['amount'] ?? null) == $this->kes(987654));
    }

    public function test_notification_text_leaves_out_fields_hidden_from_each_recipient(): void
    {
        // M3 (RBAC-05): the owner's rule (sees the amount) tells the owner and the clerk (doesn't).
        $this->saveRule(['type' => 'record_created'], [
            ['type' => 'notify', 'to' => ["user:{$this->clerk->id}", "user:{$this->owner->id}"], 'subject' => 'Task {title} {amount}', 'message' => 'Worth {amount}, {quantity} units.'],
        ]);

        $this->createTask(['amount' => $this->kes(987654), 'quantity' => '4']);

        $this->inTenant(function () {
            $notes = InAppNotification::query()->where('event_type', 'core.automation.notify')->get()->keyBy('user_id');
            $this->assertStringContainsString('Worth , 4 units.', $notes[$this->clerk->id]->body);
            $this->assertStringNotContainsString('9,876.54', $notes[$this->clerk->id]->body.$notes[$this->clerk->id]->subject);
            $this->assertStringContainsString('Worth KES 9,876.54, 4 units.', $notes[$this->owner->id]->body);
        });
    }

    public function test_the_run_log_leaves_out_checks_and_trigger_fields_hidden_from_the_reader(): void
    {
        $rule = $this->saveRule(['type' => 'record_updated', 'fields' => ['amount', 'quantity']], [['type' => 'update_field', 'field' => 'note', 'value' => 'x']], [
            'conditions' => ['all' => [['field' => 'amount', 'op' => 'gt', 'value' => $this->kes(1)], ['field' => 'quantity', 'op' => 'gt', 'value' => '1']]],
        ]);
        $id = $this->quietTask(['amount' => $this->kes(5), 'quantity' => '1']);
        $this->changeTask($id, ['amount' => $this->kes(10), 'quantity' => '5']);
        $run = $this->runs($rule)->sole();

        $owner = $this->getJson("/api/v1/automation-runs/{$run->id}", $this->headersFor())->assertOk();
        $this->assertSame(['amount', 'quantity'], array_column($owner->json('data.conditions.checks'), 'field'));
        $this->assertEqualsCanonicalizing(['amount', 'quantity'], $owner->json('data.trigger.fields'));

        $clerk = $this->getJson("/api/v1/automation-runs/{$run->id}", $this->headersFor($this->clerk))->assertOk();
        $this->assertSame(['quantity'], array_column($clerk->json('data.conditions.checks'), 'field'));
        $this->assertSame(['quantity'], $clerk->json('data.trigger.fields'));
        $this->assertStringNotContainsString('amount', json_encode($clerk->json('data.conditions')));
    }

    public function test_test_mode_shows_nothing_of_a_field_hidden_from_the_tester(): void
    {
        // The owner's rule tests the amount; the clerk may read and test it.
        $rule = $this->saveRule(['type' => 'record_created'], [
            ['type' => 'notify', 'to' => ['role:admin'], 'subject' => 'Task {title}', 'message' => 'Worth {amount}.'],
        ], ['conditions' => ['field' => 'amount', 'op' => 'gt', 'value' => $this->kes(1000000)]]);
        $id = $this->quietTask(['amount' => $this->kes(555555)]);

        $owner = $this->postJson("/api/v1/automation-rules/{$rule->id}/test", ['document_id' => $id], $this->headersFor())->assertOk();
        $this->assertStringContainsString('5,555.55', $owner->getContent(), 'control: the owner sees the amount');

        $clerk = $this->postJson("/api/v1/automation-rules/{$rule->id}/test", ['document_id' => $id], $this->headersFor($this->clerk))->assertOk();
        $this->assertStringNotContainsString('5,555.55', $clerk->getContent());
        $this->assertStringNotContainsString('555555', $clerk->getContent());
        $clerk->assertJsonPath('data.conditions.passed', false)
            ->assertJsonPath('data.conditions.checks.0', ['field' => 'amount', 'op' => 'gt', 'passed' => false, 'hidden' => true])
            ->assertJsonPath('data.conditions.reasons', ['A condition on a field you can’t see is not met.']);
        $this->assertStringContainsString('Worth .', $clerk->json('data.actions.0.description'));
    }
}
