<?php

namespace Tests\Feature\Core\Configuration;

use App\Core\Configuration\CatalogueMerge;
use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\ConfigKinds;
use App\Core\Configuration\ConfigVersions;
use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Configuration\TestLayoutKind;
use Tests\TestCase;

/**
 * LAY-06, LAY-07, RBAC-04: what applies to a user at a place. The most
 * specific published version wins along user → role(s) → location →
 * branch → company → tenant; roles are tried narrowest assignment first,
 * then oldest; the payload is merged with the current catalogue.
 */
class ConfigResolutionTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private const URL = '/api/v1/config/'.TestLayoutKind::KEY.'/resolved';

    private User $mara;

    protected function setUp(): void
    {
        parent::setUp();
        TestLayoutKind::register();
        $this->setUpOrganisation();
        // A cashier at outlet A: no configuration permission, yet reads what applies to her.
        $this->mara = $this->userWith('cashier', Scope::location($this->locationA->id));
    }

    /** Publish $columns for the key at a scope, as the owner. */
    private function publish(string $scopeType, ?string $scopeId, array $columns, string $key = 'default'): void
    {
        $this->inTenant(function () use ($scopeType, $scopeId, $columns, $key) {
            $versions = app(ConfigVersions::class);
            $kind = app(ConfigKinds::class)->get(TestLayoutKind::KEY);
            [$document] = $versions->open($kind, $key, $scopeType, $scopeId, null, ['columns' => array_map(fn ($id) => ['id' => $id], $columns)], $this->owner);
            $versions->publish($document, $kind, $this->owner, $document->draft()->value('revision'));
        });
    }

    private function resolved(string $query = '', ?User $as = null): array
    {
        return $this->getJson(self::URL.$query, $this->headersFor($as ?? $this->mara))->assertOk()->json('data');
    }

    private function firstColumn(string $query = ''): ?string
    {
        return $this->resolved($query)['payload']['columns'][0]['id'] ?? null;
    }

    public function test_the_most_specific_published_version_wins(): void
    {
        $at = '?location='.$this->locationA->id;

        // Nothing published: the kind's defaults (merged with the catalogue), no source.
        $none = $this->resolved($at);
        $this->assertNull($none['source']);
        $this->assertSame(['name', 'code', 'price'], array_column($none['payload']['columns'], 'id'));

        $steps = [
            ['tenant', null, 'price'],
            ['company', $this->acme->id, 'code'],
            ['branch', $this->branchA->id, 'name'],
            ['location', $this->locationA->id, 'price'],
            ['role', $this->roles->get('cashier')->id, 'code'],
            ['user', $this->mara->id, 'name'],
        ];

        foreach ($steps as [$type, $id, $column]) {
            $this->publish($type, $id, [$column]);
            $data = $this->resolved($at);
            $this->assertSame($column, $data['payload']['columns'][0]['id'], "after publishing at {$type}");
            $this->assertSame(['type' => $type, 'id' => $id], $data['source']['scope']);
            $this->assertSame(1, $data['source']['version']);
        }

        // Documents of other places, other users and other roles never apply.
        $this->publish('location', $this->locationB->id, ['price'], 'other');
        $this->publish('branch', $this->branchB->id, ['code'], 'other');
        $this->publish('user', $this->owner->id, ['code'], 'other');
        $this->publish('role', $this->roles->get('owner')->id, ['code'], 'other');
        $this->publish('company', $this->acme->id, ['name'], 'other');
        $this->assertSame(['type' => 'company', 'id' => $this->acme->id], $this->resolved($at.'&key=other')['source']['scope']);
    }

    public function test_the_place_is_completed_from_its_most_specific_level_and_drafts_never_apply(): void
    {
        $this->publish('branch', $this->branchA->id, ['code']);
        $this->inTenant(function () {
            $kind = app(ConfigKinds::class)->get(TestLayoutKind::KEY);
            app(ConfigVersions::class)->open($kind, 'default', 'location', $this->locationA->id, null, ['columns' => [['id' => 'price']]], $this->owner);
        });

        // The location's branch is found from the location; the location's draft is ignored.
        $this->assertSame('code', $this->firstColumn('?location='.$this->locationA->id));
        // Without a place only the user, their roles and the tenant count.
        $this->assertSame(['name', 'code', 'price'], array_column($this->resolved()['payload']['columns'], 'id'));
        $this->assertNull($this->resolved()['source']);
    }

    public function test_roles_are_tried_narrowest_assignment_first_then_oldest(): void
    {
        [$company, $first, $second, $branchB] = $this->inTenant(function () {
            $roles = [];
            foreach (['Company role', 'Outlet role one', 'Outlet role two', 'Branch B role'] as $name) {
                $roles[] = $this->role($name);
            }
            $this->assign($this->mara, $roles[0], Scope::company($this->acme->id))->forceFill(['created_at' => now()->subDays(3)])->save();
            $this->assign($this->mara, $roles[1], Scope::location($this->locationA->id))->forceFill(['created_at' => now()->subDays(2)])->save();
            $this->assign($this->mara, $roles[2], Scope::location($this->locationA->id))->forceFill(['created_at' => now()->subDay()])->save();
            $this->assign($this->mara, $roles[3], Scope::branch($this->branchB->id));

            return array_map(fn (Role $r) => $r->id, $roles);
        });
        $at = '?location='.$this->locationA->id;

        // Two roles at the same level: the older assignment wins over the newer.
        $this->publish('role', $first, ['name'], 'k1');
        $this->publish('role', $second, ['code'], 'k1');
        $this->publish('role', $company, ['price'], 'k1');
        $this->assertSame(['type' => 'role', 'id' => $first], $this->resolved($at.'&key=k1')['source']['scope']);

        // A role held at the outlet beats an older one held for the company.
        $this->publish('role', $second, ['code'], 'k2');
        $this->publish('role', $company, ['price'], 'k2');
        $this->assertSame(['type' => 'role', 'id' => $second], $this->resolved($at.'&key=k2')['source']['scope']);

        // A role held at another branch does not cover outlet A.
        $this->publish('role', $branchB, ['code'], 'k3');
        $this->publish('tenant', null, ['price'], 'k3');
        $this->assertSame(['type' => 'tenant', 'id' => null], $this->resolved($at.'&key=k3')['source']['scope']);
        // Without a place every role counts (narrowest first): branch B's then.
        $this->assertSame(['type' => 'role', 'id' => $branchB], $this->resolved('?key=k3')['source']['scope']);
    }

    public function test_the_stored_layout_is_merged_with_the_current_catalogue(): void
    {
        $this->publish('tenant', null, ['retired', 'price', 'name']);
        TestLayoutKind::$catalogue[] = ['id' => 'margin', 'width' => 2, 'after' => 'price'];

        $columns = $this->resolved('?location='.$this->locationA->id)['payload']['columns'];

        $this->assertSame(['price', 'margin', 'name', 'code'], array_column($columns, 'id'));
        $this->assertSame(4, $columns[2]['width'], 'catalogue defaults fill what the layout leaves out');
    }

    public function test_the_place_must_be_the_users_and_consistent(): void
    {
        $this->getJson(self::URL.'?branch='.$this->branchB->id, $this->headersFor($this->mara))->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->getJson(self::URL.'?company='.$this->acme->id.'&branch='.$this->branchB->id.'&location='.$this->locationA->id, $this->headersFor($this->mara))->assertUnprocessable();
        $this->getJson(self::URL.'?location=not-a-uuid', $this->headersFor($this->mara))->assertUnprocessable();
        // A role held at an outlet does not reach up to the company.
        $this->getJson(self::URL.'?company='.$this->acme->id, $this->headersFor($this->mara))->assertUnprocessable();
        $this->getJson(self::URL.'?company='.$this->acme->id.'&branch='.$this->branchA->id.'&location='.$this->locationA->id, $this->headersFor($this->mara))->assertOk();
    }

    /**
     * A published payload stored as $json, straight into the tables: the
     * validator never saw it (as after a bad import or a manual fix).
     * Written through the tenant connection inside the test transaction;
     * the owner connection could not see this test's uncommitted tenant.
     */
    private function publishRaw(string $kind, string $json, string $key = 'default'): void
    {
        $this->inTenant(function () use ($kind, $json, $key) {
            $db = DB::connection(TenantContext::CONNECTION);
            $document = (string) Str::uuid7();
            $db->table('config_documents')->insert(['id' => $document, 'kind' => $kind, 'key' => $key, 'scope_type' => 'tenant', 'scope_id' => null, 'name' => $key, 'created_at' => now(), 'updated_at' => now()]);
            $db->table('config_versions')->insert([
                'id' => (string) Str::uuid7(), 'document_id' => $document, 'version' => 1, 'revision' => 1, 'status' => 'published',
                'payload' => $json, 'source' => 'draft', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function test_a_malformed_published_payload_falls_back_to_the_defaults(): void
    {
        Log::spy();

        foreach (['"just text"' => 'scalar', '[1, 2]' => 'list', '{"columns": [1, "x", {"id": {"no": 1}}]}' => 'entries'] as $json => $key) {
            $this->publishRaw(TestLayoutKind::KEY, $json, $key);
        }
        app(ConfigKinds::class)->register(new ConfigKind(TestLayoutKind::KEY.'_broken', scopes: ConfigDocument::SCOPES, merger: fn () => throw new RuntimeException('merger bug'), defaults: fn () => null));
        $this->publishRaw(TestLayoutKind::KEY.'_broken', '{"columns": []}');

        // Not an object: the kind's defaults, no source, and a warning; never a 500.
        $scalar = $this->resolved('?key=scalar');
        $this->assertNull($scalar['source']);
        $this->assertSame(['name', 'code', 'price'], array_column($scalar['payload']['columns'], 'id'));
        // A list decodes to an array: the merger reads what it can.
        $this->assertSame(['name', 'code', 'price'], array_column($this->resolved('?key=list')['payload']['columns'], 'id'));
        // Entries that are not objects (or whose id is not text) are skipped.
        $this->assertSame(['name', 'code', 'price'], array_column($this->resolved('?key=entries')['payload']['columns'], 'id'));
        // A merger that throws: the defaults (none here).
        $this->getJson('/api/v1/config/'.TestLayoutKind::KEY.'_broken/resolved', $this->headersFor($this->mara))->assertOk()->assertJsonPath('data.source', null)->assertJsonPath('data.payload', null);

        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_grouped_layouts_with_malformed_groups_still_resolve(): void
    {
        $catalogue = [['id' => 'name', 'locked' => true, 'permission' => 'core.item.view'], ['id' => 'code'], ['id' => 'price']];
        app(ConfigKinds::class)->register(new ConfigKind(
            'test_form',
            scopes: ConfigDocument::SCOPES,
            merger: fn (array $payload, ConfigKind $kind) => ['sections' => CatalogueMerge::grouped((array) ($payload['sections'] ?? []), $catalogue, layoutKeys: $kind->layoutKeys())],
            layoutKeys: ['span'],
        ));
        $this->publishRaw('test_form', '{"sections": ["junk", 7, {"id": "main", "fields": "x"}, {"id": "more", "fields": [1, {"id": "name", "span": 2, "locked": false, "permission": null}]}]}');

        $data = $this->getJson('/api/v1/config/test_form/resolved', $this->headersFor($this->mara))->assertOk()->json('data');

        $this->assertNotNull($data['source']);
        $this->assertSame(['main', 'more'], array_column($data['payload']['sections'], 'id'));
        $this->assertSame(['code', 'price'], array_column($data['payload']['sections'][0]['fields'], 'id'));
        // The kind's own layout key is the layout's; locked and permission stay the catalogue's.
        $this->assertSame(['id' => 'name', 'locked' => true, 'permission' => 'core.item.view', 'span' => 2], $data['payload']['sections'][1]['fields'][0]);
    }
}
