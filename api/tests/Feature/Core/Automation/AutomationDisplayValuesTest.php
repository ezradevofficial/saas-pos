<?php

namespace Tests\Feature\Core\Automation;

use App\Core\Automation\Webhooks\HostResolver;
use App\Core\MasterData\Parties\Party;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\DocumentTypes\ReferenceLabels;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsAutomation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Automation\FakeHostResolver;
use Tests\Support\Automation\TestTaskType;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * AUTO-03, AUTO-04: a reference field reads as its display value (the
 * user's or party's name, never the id) in notification placeholders and
 * test mode; webhooks keep the id and add `<field>_label`; a field hidden
 * by field rules (RBAC-05) fills in as nothing.
 */
class AutomationDisplayValuesTest extends TestCase
{
    use BuildsAutomation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::fake(['*' => Http::response('ok', 200)]);
        $this->setUpAutomation();
        $this->app->instance(HostResolver::class, new FakeHostResolver(['hooks.example.com' => [['93.184.216.34']]]));
    }

    public function test_a_notification_fills_a_reference_placeholder_with_the_name(): void
    {
        $this->saveRule(['type' => 'record_created'], [$this->notifyOwner('Task for {owner}', 'Owner is {owner}.')]);

        $this->createTask(['owner' => $this->owner->id]);

        $note = $this->inTenant(fn () => InAppNotification::query()->where('event_type', 'core.automation.notify')->sole());
        $this->assertSame('Task for '.$this->owner->name, $note->subject);
        $this->assertStringContainsString('Owner is '.$this->owner->name.'.', $note->body);
        $this->assertStringNotContainsString($this->owner->id, $note->body);
    }

    public function test_a_reference_hidden_from_the_rules_user_fills_in_as_nothing(): void
    {
        $clerk = $this->inTenant(function () {
            $user = $this->colleague($this->owner, ['name' => 'Clerk']);
            $role = $this->role('Automation clerk', ['core.automation.view', 'core.automation.edit', 'core.party.view', 'core.party.edit']);
            FieldRule::create(['role_id' => $role->id, 'resource' => TestTaskType::KEY, 'field' => 'owner', 'mode' => FieldRule::HIDDEN]);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });
        $this->saveRule(['type' => 'record_created'], [[
            'type' => 'notify', 'to' => ["user:{$clerk->id}"], 'subject' => 'Task {title}', 'message' => 'Owner is [{owner}].',
        ]], [], $clerk);

        $this->createTask(['owner' => $this->owner->id]);

        $note = $this->inTenant(fn () => InAppNotification::query()->where('event_type', 'core.automation.notify')->sole());
        $this->assertStringContainsString('Owner is [].', $note->body);
    }

    public function test_test_mode_describes_a_party_by_its_name(): void
    {
        $party = $this->inTenant(fn () => Party::create(['kind' => 'organisation', 'name' => 'Duka Supplies', 'roles' => ['supplier']]));

        $this->postJson('/api/v1/automation-rules/test', [
            'document_type' => TestRequestType::KEY,
            'trigger' => ['type' => 'stage_entered'],
            'actions' => [['type' => 'notify', 'to' => ["user:{$this->owner->id}"], 'subject' => 'Request from {supplier}', 'message' => 'Supplier {supplier}.']],
            'values' => ['supplier' => $party->id],
        ], $this->headersFor())->assertOk()
            ->assertJsonPath('data.actions.0.description', fn (string $text) => str_contains($text, 'Request from Duka Supplies') && ! str_contains($text, $party->id));
    }

    public function test_a_party_name_hidden_by_the_party_field_rules_is_empty(): void
    {
        [$party, $clerk] = $this->inTenant(function () {
            $party = Party::create(['kind' => 'organisation', 'name' => 'Duka Supplies', 'roles' => ['supplier']]);
            $user = $this->colleague($this->owner, ['name' => 'Clerk']);
            $role = $this->role('No names', ['core.party.view']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'party', 'field' => 'name', 'mode' => FieldRule::HIDDEN]);
            $this->assign($user, $role, Scope::tenant());

            return [$party, $user];
        });
        $type = app(DocumentTypeRegistry::class)->get(TestRequestType::KEY);

        $this->inTenant(function () use ($type, $party, $clerk) {
            $this->assertSame(['supplier' => 'Duka Supplies'], $type->displayValuesOf(['supplier' => $party->id], $this->owner));
            $this->assertSame(['supplier' => ''], $type->displayValuesOf(['supplier' => $party->id], $clerk));
            $this->assertTrue(ReferenceLabels::knows('core.company'));
            $this->assertSame(['supplier' => 'Duka Supplies'], $type->displayValuesOf(['supplier' => $party->id]));
        });
    }

    public function test_a_webhook_keeps_the_id_and_adds_the_label(): void
    {
        $this->saveRule(['type' => 'record_created'], [['type' => 'webhook', 'url' => 'https://hooks.example.com/in']]);

        $this->createTask(['owner' => $this->owner->id]);

        $owner = $this->owner;
        Http::assertSent(function (Request $request) use ($owner) {
            $fields = json_decode($request->body(), true)['fields'];

            return $fields['owner'] === $owner->id && $fields['owner_label'] === $owner->name && ! isset($fields['title_label']);
        });
    }
}
