<?php

namespace Tests\Feature\Core\Configuration;

use App\Core\Audit\AuditEntry;
use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\ConfigKinds;
use App\Core\Configuration\ConfigVersions;
use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Configuration\Models\ConfigVersion;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Configuration\TestLayoutKind;
use Tests\TestCase;

/**
 * LAY-06, LAY-07, TEN-01, AUD-01, RBAC-04: versioned configuration through
 * the API: save a draft, publish, roll back, copy between companies,
 * discard; immutability; permissions, other tenants and out-of-scope ids.
 */
class ConfigApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private const URL = '/api/v1/config/'.TestLayoutKind::KEY;

    protected function setUp(): void
    {
        parent::setUp();
        TestLayoutKind::register();
        $this->setUpOrganisation();
    }

    private function save(array $body, ?array $headers = null, int $status = 201): array
    {
        return $this->postJson(self::URL, [
            'scope_type' => 'tenant', 'payload' => ['columns' => [['id' => 'name']]], ...$body,
        ], $headers ?? $this->headersFor())->assertStatus($status)->json();
    }

    private function url(string $documentId, string $suffix = ''): string
    {
        return self::URL."/{$documentId}{$suffix}";
    }

    /** @return list<string> */
    private function auditActions(string $documentId): array
    {
        return $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $documentId)->orderBy('seq')->pluck('action')->all());
    }

    public function test_a_draft_is_saved_published_and_then_immutable(): void
    {
        $created = $this->save(['key' => 'items', 'name' => 'Item list', 'payload' => ['columns' => [['id' => 'name', 'width' => 40]]]]);
        $id = $created['data']['id'];

        $this->assertSame(['test_layout', 'items', 'Item list', ['type' => 'tenant', 'id' => null]], [$created['data']['kind'], $created['data']['key'], $created['data']['name'], $created['data']['scope']]);
        $this->assertNull($created['data']['published']);
        $this->assertSame([1, 'draft', 'draft'], [$created['data']['draft']['version'], $created['data']['draft']['status'], $created['data']['draft']['source']]);
        // A draft may have problems; they block publishing.
        $this->assertSame([['columns.0.width', 'max']], array_map(fn ($p) => [$p['path'], $p['code']], $created['meta']['problems']));
        $this->postJson($this->url($id, '/publish'), [], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'config_invalid')->assertJsonPath('problems.0.path', 'columns.0.width');

        // Saving again for the same key and scope edits the same draft (200).
        $again = $this->save(['key' => 'items', 'payload' => ['columns' => [['id' => 'name', 'width' => 6]]]], status: 200);
        $this->assertSame([$id, 1, []], [$again['data']['id'], $again['data']['draft']['version'], $again['meta']['problems']]);
        $this->putJson($this->url($id, '/draft'), ['payload' => ['columns' => [['id' => 'code']]], 'name' => 'Items'], $this->headersFor())
            ->assertOk()->assertJsonPath('data.draft.payload.columns.0.id', 'code')->assertJsonPath('data.name', 'Items');

        $published = $this->postJson($this->url($id, '/publish'), [], $this->headersFor())->assertOk();
        $published->assertJsonPath('data.published.version', 1)->assertJsonPath('data.draft', null)
            ->assertJsonPath('data.published.payload.columns.0.id', 'code');
        $this->assertSame(['core.config.create', 'core.config.draft_create', 'core.config.draft_update', 'core.config.rename', 'core.config.draft_update', 'core.config.publish'], $this->auditActions($id));

        // LAY-06: the database refuses changes to a published payload, and deleting any version.
        $this->inTenant(function () use ($id) {
            $version = fn () => ConfigVersion::query()->where('document_id', $id)->sole();

            foreach ([fn () => $version()->forceFill(['payload' => ['columns' => []]])->save(), fn () => $version()->forceFill(['status' => 'draft'])->save(), fn () => $version()->delete()] as $change) {
                try {
                    // A savepoint each, so the next statement runs after the refusal.
                    DB::connection(TenantContext::CONNECTION)->transaction($change);
                    $this->fail('a published version changed');
                } catch (QueryException $e) {
                    $this->assertMatchesRegularExpression('/immutable|never deleted/', $e->getMessage());
                }
            }
        });
    }

    public function test_one_draft_and_one_published_version_per_document(): void
    {
        $id = $this->save([])['data']['id'];

        $this->inTenant(function () use ($id) {
            $this->expectException(QueryException::class);
            ConfigVersion::create(['document_id' => $id, 'version' => 9, 'status' => 'draft', 'payload' => [], 'source' => 'draft']);
        });
    }

    public function test_a_new_draft_becomes_the_next_version_and_rollback_publishes_a_copy(): void
    {
        $id = $this->save(['payload' => ['columns' => [['id' => 'name']]]])['data']['id'];
        $this->postJson($this->url($id, '/publish'), [], $this->headersFor())->assertOk();
        $this->putJson($this->url($id, '/draft'), ['payload' => ['columns' => [['id' => 'code']]]], $this->headersFor())->assertOk()->assertJsonPath('data.draft.version', 2);
        $this->postJson($this->url($id, '/publish'), [], $this->headersFor())->assertOk()->assertJsonPath('data.published.version', 2);

        // A draft that is discarded is never offered for roll back.
        $this->putJson($this->url($id, '/draft'), ['payload' => ['columns' => [['id' => 'price']]]], $this->headersFor())->assertOk();
        $this->postJson($this->url($id, '/discard-draft'), [], $this->headersFor())->assertOk()->assertJsonPath('data.draft', null);
        $this->postJson($this->url($id, '/rollback'), ['version' => 3], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'version_not_found');
        $this->postJson($this->url($id, '/rollback'), ['version' => 2], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'version_is_live');
        $this->postJson($this->url($id, '/discard-draft'), [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'no_draft');

        $rolled = $this->postJson($this->url($id, '/rollback'), ['version' => 1], $this->headersFor())->assertOk();
        $rolled->assertJsonPath('data.published.version', 4)
            ->assertJsonPath('data.published.source', 'rollback')
            ->assertJsonPath('data.published.payload.columns.0.id', 'name');

        $history = $rolled->json('data.history');
        $this->assertSame([[4, 'published'], [3, 'archived'], [2, 'archived'], [1, 'archived']], array_map(fn ($v) => [$v['version'], $v['status']], $history));
        $this->assertNotNull($history[1]['discarded_at']);
        $this->assertSame($history[3]['id'], $history[0]['source_version_id']);
        $this->assertArrayNotHasKey('payload', $history[0], 'history leaves payloads out');

        $this->assertSame(
            ['core.config.publish', 'core.config.publish', 'core.config.draft_discard', 'core.config.rollback'],
            array_values(array_filter($this->auditActions($id), fn ($a) => in_array($a, ['core.config.publish', 'core.config.rollback', 'core.config.draft_discard'], true))),
        );
    }

    public function test_copy_puts_the_published_payload_into_another_companys_draft(): void
    {
        $other = $this->inTenant(fn () => $this->company('Beta'));
        $id = $this->save(['scope_type' => 'company', 'scope_id' => $this->acme->id, 'name' => 'Acme items'])['data']['id'];

        $this->postJson($this->url($id, '/copy'), ['scope_type' => 'company', 'scope_id' => $other->id], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'nothing_published');

        $this->postJson($this->url($id, '/publish'), [], $this->headersFor())->assertOk();
        $copy = $this->postJson($this->url($id, '/copy'), ['scope_type' => 'company', 'scope_id' => $other->id], $this->headersFor())->assertCreated();
        $copy->assertJsonPath('data.scope', ['type' => 'company', 'id' => $other->id])
            ->assertJsonPath('data.name', 'Acme items')
            ->assertJsonPath('data.published', null)
            ->assertJsonPath('data.draft.source', 'copy')
            ->assertJsonPath('data.draft.payload.columns.0.id', 'name');
        $this->assertNotSame($id, $copy->json('data.id'));
        $this->assertSame(['core.config.copy'], $this->auditActions($copy->json('data.id')));

        // Again, from the draft: the target's draft is replaced, not duplicated.
        $this->putJson($this->url($id, '/draft'), ['payload' => ['columns' => [['id' => 'price']]]], $this->headersFor())->assertOk();
        $this->postJson($this->url($id, '/copy'), ['scope_type' => 'company', 'scope_id' => $other->id, 'from' => 'draft'], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.id', $copy->json('data.id'))->assertJsonPath('data.draft.payload.columns.0.id', 'price')->assertJsonPath('data.draft.version', 1);

        // To a branch of the tenant too; never to itself, a role, the tenant, or another tenant's company.
        $this->postJson($this->url($id, '/copy'), ['scope_type' => 'branch', 'scope_id' => $this->branchA->id], $this->headersFor())->assertCreated();
        $this->postJson($this->url($id, '/copy'), ['scope_type' => 'company', 'scope_id' => $this->acme->id], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'same_scope');
        $this->postJson($this->url($id, '/copy'), ['scope_type' => 'tenant'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('scope_type');
        $this->postJson($this->url($id, '/copy'), ['scope_type' => 'role', 'scope_id' => $this->roles->get('cashier')->id], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('scope_type');
        $foreign = $this->otherTenant();
        $this->postJson($this->url($id, '/copy'), ['scope_type' => 'company', 'scope_id' => $foreign['company']->id], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('scope_id');
        $this->assertSame(3, $this->inTenant(fn () => ConfigDocument::query()->count()));
    }

    public function test_permissions_are_checked_at_the_documents_scope(): void
    {
        $tenantDoc = $this->save([])['data']['id'];
        $branchA = $this->save(['scope_type' => 'branch', 'scope_id' => $this->branchA->id])['data']['id'];
        $branchB = $this->save(['scope_type' => 'branch', 'scope_id' => $this->branchB->id])['data']['id'];
        $personal = $this->save(['scope_type' => 'user', 'scope_id' => $this->owner->id])['data']['id'];

        // No configuration permission at all: refused.
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->getJson(self::URL, $this->headersFor($cashier))->assertForbidden();
        $this->save(['scope_type' => 'location', 'scope_id' => $this->locationA->id], $this->headersFor($cashier), 403);
        $this->getJson($this->url($tenantDoc), $this->headersFor($cashier))->assertNotFound();

        // A branch manager (view) sees the tenant's and their branch's, not branch B's or the owner's own.
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $listed = array_column($this->getJson(self::URL, $this->headersFor($manager))->assertOk()->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$tenantDoc, $branchA], $listed);
        $this->getJson($this->url($branchA), $this->headersFor($manager))->assertOk();
        $this->getJson($this->url($branchB), $this->headersFor($manager))->assertNotFound();
        $this->getJson($this->url($personal), $this->headersFor($manager))->assertNotFound();
        $this->putJson($this->url($branchA, '/draft'), ['payload' => ['columns' => []]], $this->headersFor($manager))->assertForbidden();
        $this->postJson($this->url($branchA, '/publish'), [], $this->headersFor($manager))->assertForbidden();

        // An editor at branch A edits there, but neither publishes nor reaches branch B or the tenant.
        $editor = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Layout editor', ['core.config.view', 'core.config.edit']), Scope::branch($this->branchA->id));

            return $user;
        });
        $this->putJson($this->url($branchA, '/draft'), ['payload' => ['columns' => [['id' => 'code']]]], $this->headersFor($editor))->assertOk();
        $this->save(['scope_type' => 'location', 'scope_id' => $this->locationA->id], $this->headersFor($editor));
        $this->save(['scope_type' => 'location', 'scope_id' => $this->locationB->id], $this->headersFor($editor), 403);
        $this->save(['scope_type' => 'tenant'], $this->headersFor($editor), 403);
        $this->postJson($this->url($branchA, '/publish'), [], $this->headersFor($editor))->assertForbidden();
        $this->postJson($this->url($branchA, '/rollback'), ['version' => 1], $this->headersFor($editor))->assertForbidden();
        $this->putJson($this->url($branchB, '/draft'), ['payload' => ['columns' => []]], $this->headersFor($editor))->assertNotFound();
        $this->postJson($this->url($branchA, '/copy'), ['scope_type' => 'branch', 'scope_id' => $this->branchB->id, 'from' => 'draft'], $this->headersFor($editor))->assertForbidden();
        $this->postJson($this->url($branchA, '/copy'), ['scope_type' => 'location', 'scope_id' => $this->locationA->id, 'from' => 'draft'], $this->headersFor($editor))->assertCreated();

        // Role documents are tenant-wide.
        $this->save(['scope_type' => 'role', 'scope_id' => $this->roles->get('cashier')->id], $this->headersFor($editor), 403);
        $this->save(['scope_type' => 'role', 'scope_id' => $this->roles->get('cashier')->id]);
    }

    public function test_a_user_keeps_a_personal_copy_but_not_someone_elses(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        $mine = $this->save(['scope_type' => 'user', 'scope_id' => $manager->id], $this->headersFor($manager))['data']['id'];
        $this->postJson($this->url($mine, '/publish'), [], $this->headersFor($manager))->assertOk();
        $this->assertContains($mine, array_column($this->getJson(self::URL, $this->headersFor($manager))->json('data'), 'id'));

        $this->save(['scope_type' => 'user', 'scope_id' => $this->owner->id], $this->headersFor($manager), 403);
        // The owner (tenant scope) manages anyone's.
        $this->getJson($this->url($mine), $this->headersFor())->assertOk();
    }

    public function test_other_tenants_documents_and_ids_are_not_found(): void
    {
        $foreign = $this->otherTenant();
        $theirs = $this->asTenant($foreign['user']->tenant_id, function () use ($foreign) {
            [$document] = app(ConfigVersions::class)->open(app(ConfigKinds::class)->get(TestLayoutKind::KEY), 'default', 'company', $foreign['company']->id, null, ['columns' => []], $foreign['user']);

            return $document->id;
        });

        foreach (['GET' => '', 'PUT' => '/draft', 'POST' => '/publish'] as $method => $suffix) {
            $this->json($method, $this->url($theirs, $suffix), ['payload' => ['columns' => []]], $this->headersFor())->assertNotFound();
        }
        $this->postJson($this->url($theirs, '/copy'), ['scope_type' => 'company', 'scope_id' => $this->acme->id], $this->headersFor())->assertNotFound();

        foreach (['company' => $foreign['company']->id, 'branch' => $foreign['branch']->id, 'location' => $foreign['location']->id, 'user' => $foreign['user']->id, 'tenant' => $foreign['user']->tenant_id] as $type => $scopeId) {
            $this->save(['scope_type' => $type, 'scope_id' => $scopeId], status: 422);
        }
        $this->getJson(self::URL.'/resolved?company='.$foreign['company']->id, $this->headersFor())->assertUnprocessable();
        $this->assertSame([], $this->getJson(self::URL, $this->headersFor())->json('data'));
    }

    public function test_a_kind_of_an_inactive_module_or_unknown_is_not_found(): void
    {
        $this->getJson('/api/v1/config/unknown_kind', $this->headersFor())->assertNotFound();
        $this->getJson('/api/v1/config/unknown_kind/resolved', $this->headersFor())->assertNotFound();

        app(ModuleRegistry::class)->register('isolation');
        TestLayoutKind::register('isolation');
        $this->getJson(self::URL, $this->headersFor())->assertNotFound();
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('isolation'));
        $this->getJson(self::URL, $this->headersFor())->assertOk();
    }

    public function test_a_document_of_another_kind_is_not_found_under_this_kind(): void
    {
        app(ConfigKinds::class)->register(new ConfigKind('other_layout'));
        $id = $this->save([])['data']['id'];

        $this->getJson("/api/v1/config/other_layout/{$id}", $this->headersFor())->assertNotFound();
    }

    public function test_payloads_and_keys_are_validated(): void
    {
        $this->save(['payload' => 'columns'], status: 422);
        $this->save(['key' => 'Not A Key'], status: 422);
        $this->save(['scope_type' => 'planet'], status: 422);
        $this->save(['scope_type' => 'company'], status: 422);
        $this->save(['payload' => ['columns' => [['id' => str_repeat('x', 300000)]]]], status: 422);
    }
}
