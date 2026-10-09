<?php

namespace Tests\Feature\Core\Layouts;

use App\Core\Identity\Models\User;
use App\Core\Layouts\Dashboards\DashboardSources;
use App\Core\Layouts\Dashboards\Sources\ApprovalsMine;
use App\Core\Layouts\Dashboards\Sources\ApprovalsWaiting;
use App\Core\Layouts\Dashboards\Sources\Shortcuts;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * LAY-01, LAY-02, LAY-04, LAY-07, RBAC-01, RBAC-05, RBAC-09, TEN-01:
 * the dashboard, navigation and list view kinds validate, resolve per
 * role and user, keep personal copies to their user, respect field rules
 * and source permissions, and never cross tenants.
 */
class LayoutDesignersTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private User $cashier;

    /** @var array<string, ?int> the draft revision left per kind, key and scope */
    private array $revisions = [];

    /** A greeter at outlet A: a role with no permissions at all. */
    private User $greeter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganisation();
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->greeter = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->roles->put('greeter', $role = $this->role('Greeter'));
            $this->assign($user, $role, Scope::location($this->locationA->id));

            return $user;
        });
    }

    /** Saves a draft (creating the document) and publishes it; returns the publish response. */
    private function publish(string $kind, array $payload, string $scopeType = 'tenant', ?string $scopeId = null, string $key = 'default', ?User $as = null)
    {
        // A draft left by a refused publish is edited from its revision.
        $at = "{$kind}|{$key}|{$scopeType}|{$scopeId}";
        $saved = $this->postJson("/api/v1/config/{$kind}", ['key' => $key, 'scope_type' => $scopeType, 'scope_id' => $scopeId, 'revision' => $this->revisions[$at] ?? null, 'payload' => $payload], $this->headersFor($as));
        $this->assertContains($saved->status(), [200, 201], (string) $saved->getContent());

        $published = $this->postJson("/api/v1/config/{$kind}/{$saved->json('data.id')}/publish", ['revision' => $saved->json('data.draft.revision')], $this->headersFor($as));
        $this->revisions[$at] = $published->isOk() ? null : $saved->json('data.draft.revision');

        return $published;
    }

    private function resolved(string $kind, ?User $as = null, string $key = 'default'): array
    {
        return $this->getJson("/api/v1/config/{$kind}/resolved?key={$key}", $this->headersFor($as))->assertOk()->json('data');
    }

    private static function widget(string $id, string $type, string $source, int $x, int $y, int $w = 3, int $h = 1, array $params = []): array
    {
        return ['id' => $id, 'type' => $type, 'source' => $source, 'params' => $params, 'title' => null, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
    }

    /** @return list<string> problem codes of a refused publish */
    private function problemCodes($response): array
    {
        $response->assertUnprocessable()->assertJsonPath('code', 'config_invalid');

        return array_column($response->json('problems'), 'code');
    }

    public function test_the_layout_permissions_are_in_the_catalogue_and_held_by_owner_and_admin(): void
    {
        $admin = $this->userWith('admin', Scope::tenant());

        foreach ([$this->owner, $admin] as $holder) {
            $names = array_column($this->getJson('/api/v1/me/permissions', $this->headersFor($holder))->assertOk()->json('permissions'), 'name');
            foreach (['core.layout.view', 'core.layout.edit', 'core.layout.publish'] as $permission) {
                $this->assertContains($permission, $names);
            }
        }

        $this->publish('navigation', ['groups' => [], 'home' => '/'], as: $admin)->assertOk();
        // A cashier holds none of them: the tenant's navigation and other people's views are not theirs to change.
        $this->postJson('/api/v1/config/navigation', ['scope_type' => 'tenant', 'payload' => ['groups' => []]], $this->headersFor($this->cashier))->assertForbidden();
    }

    public function test_dashboards_validate_the_grid_the_sources_and_their_parameters(): void
    {
        $codes = $this->problemCodes($this->publish('dashboard', ['widgets' => [
            self::widget('wide', 'kpi', 'approvals.waiting', 10, 0, 4),
            self::widget('a', 'approval_count', 'approvals.waiting', 0, 0, 4, 2),
            self::widget('b', 'list', 'approvals.mine', 2, 1, 4, 2),
            self::widget('c', 'kpi', 'nowhere.to_be_found', 0, 5),
            self::widget('d', 'chart', 'approvals.mine', 4, 6),
            self::widget('e', 'list', 'approvals.mine', 8, 6, 4, 2, ['limit' => 50]),
        ]]));
        $this->assertSame(['off_grid', 'overlap', 'unknown_source', 'source_widget', 'params'], $codes);

        $this->publish('dashboard', ['title' => 'Front desk', 'widgets' => [
            self::widget('a', 'approval_count', 'approvals.waiting', 0, 0, 4, 2),
            self::widget('b', 'list', 'approvals.mine', 4, 0, 8, 3, ['limit' => 5]),
            self::widget('c', 'shortcut', 'shortcuts', 0, 2, 4, 2, ['links' => ['/catalogue/items']]),
        ]])->assertOk()->assertJsonPath('data.published.version', 1);
    }

    public function test_dashboards_resolve_per_role_and_user_and_drop_widgets_the_reader_cannot_open(): void
    {
        // Nothing published: the default dashboard (getting started and approvals).
        $default = $this->resolved('dashboard', $this->cashier);
        $this->assertNull($default['source']);
        $this->assertSame(['start', 'waiting', 'mine'], array_column($default['payload']['widgets'], 'id'));

        $this->publish('dashboard', ['widgets' => [self::widget('t', 'approval_count', 'approvals.waiting', 0, 0)]])->assertOk();
        $this->publish('dashboard', ['widgets' => [
            self::widget('waiting', 'approval_count', 'approvals.waiting', 0, 0),
            self::widget('overdue', 'kpi', 'workflows.overdue', 3, 0),
        ]], 'role', $this->roles->get('greeter')->id)->assertOk();

        // The greeter gets their role's dashboard, without the workflow widget they can't open (RBAC-09).
        $greeter = $this->resolved('dashboard', $this->greeter);
        $this->assertSame(['type' => 'role', 'id' => $this->roles->get('greeter')->id], $greeter['source']['scope']);
        $this->assertSame(['waiting'], array_column($greeter['payload']['widgets'], 'id'));
        // The cashier's role has none: the tenant's.
        $this->assertSame(['t'], array_column($this->resolved('dashboard', $this->cashier)['payload']['widgets'], 'id'));

        // "Customise my dashboard": the cashier keeps a personal copy without any layout permission.
        $this->publish('dashboard', ['widgets' => [self::widget('mine', 'list', 'approvals.mine', 0, 0, 6, 3)]], 'user', $this->cashier->id, as: $this->cashier)->assertOk();
        $this->assertSame(['type' => 'user', 'id' => $this->cashier->id], $this->resolved('dashboard', $this->cashier)['source']['scope']);
        // Nobody else's resolution changes, and the cashier can't write someone else's copy.
        $this->assertSame(['t'], array_column($this->resolved('dashboard')['payload']['widgets'], 'id'));
        $this->postJson('/api/v1/config/dashboard', ['scope_type' => 'user', 'scope_id' => $this->owner->id, 'payload' => ['widgets' => []]], $this->headersFor($this->cashier))->assertForbidden();
        // Their own documents are all they list.
        $listed = $this->getJson('/api/v1/config/dashboard', $this->headersFor($this->cashier))->assertOk()->json('data');
        $this->assertSame([['type' => 'user', 'id' => $this->cashier->id]], array_column($listed, 'scope'));
    }

    public function test_widgets_of_sources_that_are_gone_or_switched_off_are_skipped(): void
    {
        $this->publish('dashboard', ['widgets' => [
            self::widget('waiting', 'approval_count', 'approvals.waiting', 0, 0),
            self::widget('overdue', 'kpi', 'workflows.overdue', 3, 0),
        ]])->assertOk();

        // A platform update removes the workflow source: the stored dashboard still renders, without it (LAY-07).
        $registry = new DashboardSources(app(ModuleRegistry::class));
        foreach ([ApprovalsWaiting::class, ApprovalsMine::class, Shortcuts::class] as $source) {
            $registry->register(new $source);
        }
        $this->app->instance(DashboardSources::class, $registry);

        $this->assertSame(['waiting'], array_column($this->resolved('dashboard')['payload']['widgets'], 'id'));
        $this->getJson('/api/v1/dashboard/sources/workflows.overdue', $this->headersFor())->assertNotFound();
    }

    public function test_data_sources_answer_only_readers_who_may_open_them(): void
    {
        $keys = array_column($this->getJson('/api/v1/dashboard/sources', $this->headersFor($this->greeter))->assertOk()->json('data'), 'key');
        $this->assertSame(['approvals.mine', 'approvals.waiting', 'shortcuts'], $keys);
        $this->assertContains('workflows.overdue', array_column($this->getJson('/api/v1/dashboard/sources', $this->headersFor())->json('data'), 'key'));

        $this->getJson('/api/v1/dashboard/sources/workflows.overdue', $this->headersFor($this->greeter))->assertForbidden();
        $this->getJson('/api/v1/dashboard/sources/workflows.overdue', $this->headersFor())->assertOk()->assertJsonPath('data.value', 0);
        $this->getJson('/api/v1/dashboard/sources/approvals.waiting', $this->headersFor($this->cashier))->assertOk()
            ->assertJsonPath('data', ['kind' => 'number', 'value' => 0, 'to' => '/approvals']);
        $this->getJson('/api/v1/dashboard/sources/approvals.mine?limit=3', $this->headersFor($this->cashier))->assertOk()
            ->assertJsonPath('data.total', 0)->assertJsonPath('data.rows', []);
        $this->getJson('/api/v1/dashboard/sources/approvals.mine?limit=50', $this->headersFor($this->cashier))->assertUnprocessable()->assertJsonValidationErrors('limit');
        $this->getJson('/api/v1/dashboard/sources/shortcuts?links[]=/catalogue/items&links[]=/catalogue/items', $this->headersFor($this->cashier))->assertOk()
            ->assertJsonPath('data.links', ['/catalogue/items']);
        $this->getJson('/api/v1/dashboard/sources/shortcuts?links[]=https://example.com', $this->headersFor($this->cashier))->assertUnprocessable();
        $this->getJson('/api/v1/dashboard/sources/pos.sales_today', $this->headersFor())->assertNotFound();
        $this->getJson('/api/v1/dashboard/sources/nothing', $this->headersFor())->assertNotFound();
    }

    public function test_navigation_validates_and_resolves_per_role(): void
    {
        $codes = $this->problemCodes($this->publish('navigation', ['groups' => [
            ['id' => 'catalogue', 'items' => [['id' => '/catalogue/items']]],
            ['id' => 'contacts', 'items' => [['id' => '/catalogue/items'], ['id' => 'javascript:alert(1)']]],
        ], 'home' => 'https://example.com']));
        $this->assertEqualsCanonicalizing(['pattern', 'pattern', 'duplicate'], $codes);

        $cashierRole = $this->roles->get('cashier')->id;
        $this->publish('navigation', ['groups' => [
            ['id' => 'pos', 'label' => 'Till', 'items' => [['id' => '/pos/sales', 'label' => 'Receipts'], ['id' => '/pos/held', 'hidden' => true]]],
        ], 'home' => '/pos/sales'], 'role', $cashierRole)->assertOk();

        $data = $this->resolved('navigation', $this->cashier);
        $this->assertSame('/pos/sales', $data['payload']['home']);
        $this->assertSame('Receipts', $data['payload']['groups'][0]['items'][0]['label']);
        // The owner's role has no layout: the catalogue's own (no payload, the web keeps its default).
        $this->assertNull($this->resolved('navigation')['source']);
        // Navigation is per tenant or role: no personal copies, no other keys.
        $this->postJson('/api/v1/config/navigation', ['scope_type' => 'user', 'scope_id' => $this->owner->id, 'payload' => ['groups' => []]], $this->headersFor())->assertUnprocessable();
        $this->postJson('/api/v1/config/navigation', ['key' => 'other', 'scope_type' => 'tenant', 'payload' => ['groups' => []]], $this->headersFor())->assertUnprocessable();
    }

    public function test_list_views_are_shared_or_personal_and_offer_every_layer(): void
    {
        $view = fn (string $id, string $name, array $columns, array $extra = []) => ['id' => $id, 'name' => $name, 'columns' => array_map(fn ($c) => ['id' => $c, 'visible' => true], $columns), ...$extra];

        $this->assertSame(['filter_value', 'unknown_view'], $this->problemCodes($this->publish('list_view', [
            'views' => [$view('all', 'All', ['name'], ['filters' => ['status' => ['x']]])], 'default_view' => 'missing',
        ], key: 'items')));

        $this->publish('list_view', ['views' => [$view('everyone', 'Everyone', ['code', 'name'], ['sort' => 'name', 'per_page' => 50])], 'default_view' => 'everyone'], key: 'items')->assertOk();
        $this->publish('list_view', ['views' => [$view('tills', 'Till items', ['name', 'barcodes'], ['filters' => ['status' => 'active']])], 'default_view' => 'tills'], 'role', $this->roles->get('cashier')->id, 'items')->assertOk();
        // A personal view needs no layout permission.
        $this->publish('list_view', ['views' => [$view('mine', 'Mine', ['name'])], 'default_view' => null], 'user', $this->cashier->id, 'items', $this->cashier)->assertOk();

        $layers = $this->getJson('/api/v1/config/list_view/layers?key=items', $this->headersFor($this->cashier))->assertOk()->json('data');
        $this->assertSame(['user', 'role', 'tenant'], array_map(fn ($l) => $l['source']['scope']['type'], $layers));
        $this->assertSame(['mine', 'tills', 'everyone'], array_map(fn ($l) => $l['payload']['views'][0]['id'], $layers));
        // The owner sees only the tenant's (and their own) views.
        $this->assertSame(['tenant'], array_map(fn ($l) => $l['source']['scope']['type'], $this->getJson('/api/v1/config/list_view/layers?key=items', $this->headersFor())->json('data')));
        // Nobody else's personal view is listed to them.
        $this->assertSame([], $this->getJson('/api/v1/config/list_view?key=items', $this->headersFor($this->userWith('cashier', Scope::location($this->locationB->id))))->assertOk()->json('data'));
    }

    public function test_a_view_never_shows_a_column_or_sort_the_readers_field_rules_hide(): void
    {
        $this->publish('list_view', ['views' => [[
            'id' => 'all', 'name' => 'All', 'sort' => '-code',
            'columns' => [['id' => 'code', 'visible' => true], ['id' => 'name', 'visible' => true]],
        ]], 'default_view' => 'all'], key: 'items')->assertOk();

        $this->inTenant(fn () => FieldRule::create(['role_id' => $this->roles->get('cashier')->id, 'resource' => 'item', 'field' => 'code', 'mode' => FieldRule::HIDDEN]));

        $cashier = $this->resolved('list_view', $this->cashier, 'items')['payload'];
        $this->assertSame(['name'], array_column($cashier['views'][0]['columns'], 'id'));
        $this->assertNull($cashier['views'][0]['sort']);
        $this->assertSame(['code'], $cashier['hidden_columns']);

        $owner = $this->resolved('list_view', key: 'items')['payload'];
        $this->assertSame(['code', 'name'], array_column($owner['views'][0]['columns'], 'id'));
        $this->assertSame([], $owner['hidden_columns']);
    }

    public function test_another_tenants_layouts_never_apply_and_are_never_listed(): void
    {
        $this->publish('dashboard', ['widgets' => [self::widget('a', 'approval_count', 'approvals.waiting', 0, 0)]])->assertOk();
        $this->publish('list_view', ['views' => [['id' => 'v', 'name' => 'Mine', 'columns' => []]]], 'user', $this->cashier->id, 'items', $this->cashier)->assertOk();

        $other = $this->otherTenant()['user'];
        $this->assertNull($this->resolved('dashboard', $other)['source']);
        $this->assertSame([], $this->getJson('/api/v1/config/list_view/layers?key=items', $this->headersFor($other))->assertOk()->json('data'));
        $this->assertSame([], $this->getJson('/api/v1/config/dashboard', $this->headersFor($other))->assertOk()->json('data'));
        // Tenant A's user can't be named as the scope of a tenant B document.
        $this->postJson('/api/v1/config/list_view', ['key' => 'items', 'scope_type' => 'user', 'scope_id' => $this->cashier->id, 'payload' => ['views' => []]], $this->headersFor($other))
            ->assertUnprocessable()->assertJsonValidationErrors('scope_id');
    }
}
