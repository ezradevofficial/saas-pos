<?php

namespace Tests\Feature\Core\Automation;

use App\Core\Audit\AuditEntry;
use App\Core\Automation\Models\AutomationRule;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsAutomation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Automation\TestTaskType;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * AUTO-01..AUTO-03 API: rules are created, read, edited (each change a new
 * version), enabled, disabled and archived (never deleted), every change
 * audited without the webhook secret, which is never returned. Rules are
 * validated whole (trigger, conditions, actions, the author's rights).
 * `core.automation.view|edit` at the company (tenant scope for a rule of
 * every company); another tenant's rules are not found. The catalogue
 * tells the editor what each document type offers.
 */
class AutomationRuleApiTest extends TestCase
{
    use BuildsAutomation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpAutomation();
    }

    private function body(array $overrides = []): array
    {
        return [
            'name' => 'Flag big tasks',
            'document_type' => TestTaskType::KEY,
            'trigger' => ['type' => 'record_created'],
            'conditions' => ['field' => 'amount', 'op' => 'gte', 'value' => $this->kes(100000)],
            'actions' => [
                ['type' => 'update_field', 'field' => 'urgent', 'value' => true],
                ['type' => 'notify', 'to' => ['role:admin', 'field:owner'], 'subject' => 'Big task {title}', 'message' => 'Worth {amount}.'],
            ],
            ...$overrides,
        ];
    }

    public function test_the_owner_creates_reads_and_lists_a_rule_saved_off_by_default(): void
    {
        $created = $this->postJson('/api/v1/automation-rules', $this->body(), $this->headersFor())->assertCreated();

        $created->assertJsonPath('data.name', 'Flag big tasks')
            ->assertJsonPath('data.document_type', TestTaskType::KEY)
            ->assertJsonPath('data.company_id', null)
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.has_webhook_secret', false)
            ->assertJsonPath('data.trigger_description', 'When a Document is created')
            ->assertJsonPath('data.actions.1.to', ['role:admin', 'field:owner']);

        $id = $created->json('data.id');
        $this->getJson("/api/v1/automation-rules/{$id}", $this->headersFor())->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame([$id], array_column($this->getJson('/api/v1/automation-rules', $this->headersFor())->assertOk()->json('data'), 'id'));

        $audit = $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.automation.create')->sole());
        $this->assertSame($id, $audit->auditable_id);
        $this->assertSame('Flag big tasks', $audit->after['name']);
        $this->assertFalse($audit->after['has_webhook_secret']);
    }

    public function test_the_webhook_secret_is_stored_encrypted_and_never_returned_or_audited(): void
    {
        $secret = 'a-long-shared-secret-1234';
        $response = $this->postJson('/api/v1/automation-rules', $this->body([
            'actions' => [['type' => 'webhook', 'url' => 'https://hooks.example.com/in']],
            'webhook_secret' => $secret,
        ]), $this->headersFor())->assertCreated();

        $id = $response->json('data.id');
        $response->assertJsonPath('data.has_webhook_secret', true);
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString($secret, $this->getJson("/api/v1/automation-rules/{$id}", $this->headersFor())->getContent());
        $this->assertStringNotContainsString($secret, $this->getJson('/api/v1/automation-rules', $this->headersFor())->getContent());
        $this->inTenant(function () use ($id, $secret) {
            $stored = DB::table('automation_rules')->where('id', $id)->value('webhook_secret');
            $this->assertNotSame($secret, $stored);
            $this->assertSame($secret, AutomationRule::query()->find($id)->webhook_secret);
            $this->assertStringNotContainsString($secret, json_encode(AuditEntry::query()->get()->toArray()));
        });

        // Removing the secret while a webhook remains is refused; with the webhook gone it is allowed.
        $this->patchJson("/api/v1/automation-rules/{$id}", ['webhook_secret' => null], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
        $this->patchJson("/api/v1/automation-rules/{$id}", ['webhook_secret' => null, 'actions' => [$this->notifyOwner()]], $this->headersFor())
            ->assertOk()->assertJsonPath('data.has_webhook_secret', false);
    }

    public function test_rules_are_validated_whole(): void
    {
        $post = fn (array $overrides) => $this->postJson('/api/v1/automation-rules', $this->body($overrides), $this->headersFor());

        $post(['name' => ''])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $post(['document_type' => 'core.nothing'])->assertUnprocessable()->assertJsonValidationErrors(['document_type']);
        $post(['trigger' => ['type' => 'whenever']])->assertUnprocessable()->assertJsonValidationErrors(['trigger']);
        $post(['trigger' => ['type' => 'field_changed', 'field' => 'nope']])->assertUnprocessable()->assertJsonValidationErrors(['trigger']);
        $post(['trigger' => ['type' => 'threshold', 'field' => 'title', 'value' => '1', 'direction' => 'down']])->assertUnprocessable()->assertJsonValidationErrors(['trigger']);
        $post(['trigger' => ['type' => 'schedule', 'every' => 'week', 'time' => '25:00']])->assertUnprocessable()->assertJsonValidationErrors(['trigger']);
        $post(['conditions' => ['field' => 'amount', 'op' => 'gt', 'value' => 5]])->assertUnprocessable()->assertJsonValidationErrors(['conditions']);
        $post(['actions' => []])->assertUnprocessable()->assertJsonValidationErrors(['actions']);
        $post(['actions' => [['type' => 'explode']]])->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
        $post(['actions' => [['type' => 'update_field', 'field' => 'title', 'value' => 'x']]])->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
        $post(['actions' => [['type' => 'update_field', 'field' => 'status', 'value' => 'lost']]])->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
        $post(['actions' => [['type' => 'notify', 'to' => ['role:no_such_role'], 'subject' => 'x', 'message' => 'y']]])->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
        $post(['actions' => [['type' => 'notify', 'to' => ['field:title'], 'subject' => 'x', 'message' => 'y']]])->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
        $post(['actions' => [['type' => 'notify', 'to' => ['role:admin'], 'subject' => 'x {secret_field}', 'message' => 'y']]])->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
        $post(['actions' => [['type' => 'webhook', 'url' => 'http://hooks.example.com']]])->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
        $post(['actions' => [['type' => 'webhook', 'url' => 'https://169.254.169.254/latest']], 'webhook_secret' => 'a-long-shared-secret-1234'])->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
        $post(['actions' => [['type' => 'webhook', 'url' => 'https://hooks.example.com']], 'webhook_secret' => 'short'])->assertUnprocessable()->assertJsonValidationErrors(['webhook_secret']);
        $post(['actions' => [['type' => 'create_document', 'target' => TestOrderTypeKey::KEY, 'mapping' => ['amount' => 'title']]]])->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
        // A schedule has no document: no conditions, no document actions.
        $post(['trigger' => ['type' => 'schedule', 'every' => 'day', 'time' => '08:00'], 'conditions' => null])->assertUnprocessable()->assertJsonValidationErrors(['actions.0']);
        $post(['trigger' => ['type' => 'schedule', 'every' => 'day', 'time' => '08:00'], 'actions' => [$this->notifyOwner('Daily', 'Count.')]])->assertUnprocessable()->assertJsonValidationErrors(['conditions']);
        $post(['trigger' => ['type' => 'schedule', 'every' => 'day', 'time' => '08:00'], 'conditions' => null, 'actions' => [$this->notifyOwner('Daily', 'Count.')]])->assertCreated();
        // Dates only for types that can be searched by date.
        $this->postJson('/api/v1/automation-rules', [...$this->body(), 'document_type' => TestRequestType::KEY, 'conditions' => null,
            'trigger' => ['type' => 'date', 'field' => 'needed_by', 'days' => 1, 'when' => 'before'], 'actions' => [['type' => 'notify', 'to' => ['role:admin'], 'subject' => 'x', 'message' => 'y']]], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['trigger']);

        $this->assertSame(1, $this->inTenant(fn () => AutomationRule::query()->count()));
    }

    public function test_a_rule_never_acts_beyond_its_author(): void
    {
        // Automation editor who may read tasks but not change them.
        $editor = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Automation clerk', ['core.automation.edit', 'core.automation.view', 'core.party.view']), Scope::tenant());

            return $user;
        });

        $this->postJson('/api/v1/automation-rules', $this->body(), $this->headersFor($editor))
            ->assertUnprocessable()->assertJsonValidationErrors(['actions.0'])->assertJsonMissingValidationErrors(['actions.1']);
        $this->postJson('/api/v1/automation-rules', $this->body(['actions' => [$this->notifyOwner()]]), $this->headersFor($editor))->assertCreated();
    }

    public function test_permissions_are_checked_at_the_rules_company(): void
    {
        $beta = $this->inTenant(fn () => $this->company('Beta'));
        $acmeEditor = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Acme automation', ['core.automation.edit', 'core.party.view', 'core.party.edit']), Scope::company($this->acme->id));

            return $user;
        });
        $auditor = $this->userWith('read_only_auditor', Scope::tenant());
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));

        $this->postJson('/api/v1/automation-rules', $this->body(), $this->headersFor($acmeEditor))->assertForbidden();
        $acmeRule = $this->postJson('/api/v1/automation-rules', $this->body(['company_id' => $this->acme->id]), $this->headersFor($acmeEditor))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/automation-rules', $this->body(['company_id' => $beta->id]), $this->headersFor($acmeEditor))->assertUnprocessable()->assertJsonValidationErrors(['company_id']);
        $this->patchJson("/api/v1/automation-rules/{$acmeRule}", ['company_id' => null], $this->headersFor($acmeEditor))->assertForbidden();

        $betaRule = $this->postJson('/api/v1/automation-rules', $this->body(['company_id' => $beta->id]), $this->headersFor())->assertCreated()->json('data.id');
        $this->getJson("/api/v1/automation-rules/{$betaRule}", $this->headersFor($acmeEditor))->assertNotFound();
        $this->postJson("/api/v1/automation-rules/{$betaRule}/enable", [], $this->headersFor($acmeEditor))->assertNotFound();

        // The auditor reads every rule and changes none.
        $this->assertCount(2, $this->getJson('/api/v1/automation-rules', $this->headersFor($auditor))->assertOk()->json('data'));
        $this->patchJson("/api/v1/automation-rules/{$acmeRule}", ['name' => 'x'], $this->headersFor($auditor))->assertForbidden();
        $this->postJson("/api/v1/automation-rules/{$acmeRule}/archive", [], $this->headersFor($auditor))->assertForbidden();
        $this->postJson('/api/v1/automation-rules', $this->body(), $this->headersFor($auditor))->assertForbidden();

        // No automation permission at all.
        $this->getJson('/api/v1/automation-rules', $this->headersFor($cashier))->assertForbidden();
        $this->getJson('/api/v1/automation/catalogue', $this->headersFor($cashier))->assertForbidden();
        $this->getJson("/api/v1/automation-rules/{$acmeRule}", $this->headersFor($cashier))->assertNotFound();
    }

    public function test_edits_raise_the_version_and_are_audited_and_switching_is_audited_too(): void
    {
        $id = $this->postJson('/api/v1/automation-rules', $this->body(), $this->headersFor())->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/automation-rules/{$id}", ['name' => 'Flag very big tasks', 'conditions' => null], $this->headersFor())->assertOk()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.name', 'Flag very big tasks')->assertJsonPath('data.conditions', null);
        $this->patchJson("/api/v1/automation-rules/{$id}", ['name' => 'Flag very big tasks'], $this->headersFor())->assertOk()
            ->assertJsonPath('data.version', 2);

        $this->postJson("/api/v1/automation-rules/{$id}/enable", [], $this->headersFor())->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.status', 'enabled');
        $this->postJson("/api/v1/automation-rules/{$id}/disable", [], $this->headersFor())->assertOk()->assertJsonPath('data.enabled', false);
        $this->postJson("/api/v1/automation-rules/{$id}/archive", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'archived');
        $this->postJson("/api/v1/automation-rules/{$id}/enable", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'rule_archived');

        $this->inTenant(function () use ($id) {
            $this->assertSame(['core.automation.create', 'core.automation.update', 'core.automation.enable', 'core.automation.disable', 'core.automation.archive'],
                AuditEntry::query()->where('auditable_id', $id)->orderBy('seq')->pluck('action')->all());
            $update = AuditEntry::query()->where('action', 'core.automation.update')->sole();
            $this->assertSame('Flag big tasks', $update->before['name']);
            $this->assertSame(1, $update->before['version']);
            $this->assertSame(2, $update->after['version']);
            $this->assertTrue(AutomationRule::query()->whereKey($id)->exists(), 'archived, never deleted');
        });

        // The list hides archived rules by default.
        $this->assertSame([], $this->getJson('/api/v1/automation-rules', $this->headersFor())->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/automation-rules?status=archived', $this->headersFor())->json('data'));
    }

    public function test_enabling_checks_the_rule_again(): void
    {
        $id = $this->postJson('/api/v1/automation-rules', $this->body(['actions' => [['type' => 'notify', 'to' => ['role:'.$this->inTenant(fn () => $this->role('Temp')->id)], 'subject' => 's', 'message' => 'm']]]), $this->headersFor())
            ->assertCreated()->json('data.id');
        $this->inTenant(fn () => Role::query()->where('name', 'Temp')->update(['archived_at' => now()]));

        $this->postJson("/api/v1/automation-rules/{$id}/enable", [], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'rule_invalid')->assertJsonStructure(['errors' => ['actions.0']]);
    }

    public function test_the_list_searches_filters_sorts_and_exports(): void
    {
        $this->postJson('/api/v1/automation-rules', $this->body(['name' => 'Alpha reminder', 'enabled' => true]), $this->headersFor())->assertCreated();
        $this->postJson('/api/v1/automation-rules', $this->body(['name' => 'Beta alert', 'company_id' => $this->acme->id]), $this->headersFor())->assertCreated();
        $this->postJson('/api/v1/automation-rules', [...$this->body(['name' => 'Gamma requests', 'document_type' => TestRequestType::KEY, 'conditions' => null]),
            'actions' => [['type' => 'notify', 'to' => ['role:admin'], 'subject' => 's', 'message' => 'm']]], $this->headersFor())->assertCreated();
        $names = fn (string $query) => array_column($this->getJson('/api/v1/automation-rules'.$query, $this->headersFor())->assertOk()->json('data'), 'name');

        $this->assertSame(['Alpha reminder', 'Beta alert', 'Gamma requests'], $names(''));
        $this->assertSame(['Beta alert'], $names('?search=alert'));
        $this->assertSame(['Alpha reminder'], $names('?status=enabled'));
        $this->assertSame(['Beta alert', 'Gamma requests'], $names('?status=disabled'));
        $this->assertSame(['Gamma requests'], $names('?type='.TestRequestType::KEY));
        $this->assertSame(['Gamma requests', 'Beta alert', 'Alpha reminder'], $names('?sort=-name'));
        $this->getJson('/api/v1/automation-rules?sort=secret', $this->headersFor())->assertUnprocessable();

        $csv = $this->get('/api/v1/automation-rules?format=csv&columns[]=name&columns[]=status', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('Alpha reminder', $csv);
        $this->assertStringContainsString('On', $csv);
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'core.automation.export')->exists()));
    }

    public function test_another_tenants_rules_are_not_found(): void
    {
        $id = $this->postJson('/api/v1/automation-rules', $this->body(), $this->headersFor())->assertCreated()->json('data.id');
        $other = $this->otherTenant();
        $theirs = $this->headersFor($other['user']);

        $this->getJson("/api/v1/automation-rules/{$id}", $theirs)->assertNotFound();
        $this->patchJson("/api/v1/automation-rules/{$id}", ['name' => 'Hijacked'], $theirs)->assertNotFound();
        foreach (['enable', 'disable', 'archive', 'test'] as $action) {
            $this->postJson("/api/v1/automation-rules/{$id}/{$action}", [], $theirs)->assertNotFound();
        }
        $this->assertSame([], $this->getJson('/api/v1/automation-rules?status=all', $theirs)->assertOk()->json('data'));
        $this->postJson('/api/v1/automation-rules', $this->body(['company_id' => $this->acme->id]), $theirs)->assertUnprocessable()->assertJsonValidationErrors(['company_id']);
        $this->assertSame('Flag big tasks', $this->inTenant(fn () => AutomationRule::query()->find($id)->name));
    }

    public function test_the_catalogue_tells_what_each_type_offers(): void
    {
        $data = collect($this->getJson('/api/v1/automation/catalogue', $this->headersFor())->assertOk()->json('data'))->keyBy('key');

        $task = $data[TestTaskType::KEY];
        $this->assertSame(['status', 'urgent', 'quantity', 'note'], $task['writable_fields']);
        $this->assertSame(['owner'], $task['assignable_fields']);
        $this->assertSame(['owner'], $task['user_fields']);
        $this->assertSame(['due_on'], $task['date_fields']);
        $this->assertSame(['update_fields', 'assign_users', 'credit_hold', 'dates', 'create_drafts', 'links'], $task['capabilities']);
        $this->assertContains('date', $task['triggers']);
        $this->assertContains('set_credit_hold', $task['actions']);
        $this->assertContains('gt', collect($task['fields'])->firstWhere('name', 'amount')['operators']);

        $request = $data[TestRequestType::KEY];
        $this->assertSame([], $request['writable_fields']);
        $this->assertNotContains('date', $request['triggers']);
        $this->assertEqualsCanonicalizing(['change_stage', 'create_document', 'notify', 'webhook'], $request['actions']);

        $meta = $this->getJson('/api/v1/automation/catalogue', $this->headersFor())->json('meta');
        $this->assertSame(['day', 'week', 'month'], $meta['schedule']['every']);
        $this->assertContains('webhook', array_column($meta['actions'], 'key'));
    }
}

/** The order type's key, for readability above. */
final class TestOrderTypeKey
{
    public const KEY = 'core.test_order';
}
