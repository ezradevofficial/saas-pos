<?php

namespace Modules\POS\Tests;

use App\Core\Branding\Models\BrandAsset;
use App\Core\Configuration\Models\ConfigDocument;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\POS\Layout\PosLayout;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * LAY-05, LAY-06, LAY-07, NFR-04, BR-02: the POS layout kind (validation
 * with problems, permissions, module switch), its resolution for a till
 * (location → branch → company → tenant), the `pos_layout` sync entity
 * (scoped to the device's location, merged with what the till holds) and
 * brand assets for devices; other tenants never reach either.
 */
class PosLayoutTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    private const URL = '/api/v1/config/pos_layout';

    private ItemCategory $drinks;

    private ItemCategory $snacks;

    private Item $cola;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPos();
        $this->inTenant(function () {
            $this->drinks = ItemCategory::create(['name' => 'Drinks']);
            $this->snacks = ItemCategory::create(['name' => 'Snacks']);
            $this->cola = Item::create(['code' => 'COLA', 'name' => 'Coca-Cola 500 ml', 'type' => 'stock', 'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id, 'category_id' => $this->drinks->id]);
        });
    }

    /** A valid layout; $overrides replace whole sections, but grid, products and customer_display key by key. */
    private function layout(array $overrides = []): array
    {
        $base = [
            'grid' => ['tablet_columns' => 4, 'phone_columns' => 2, 'tile_size' => 'standard'],
            'products' => ['pinned' => [$this->cola->id], 'order' => 'category'],
            'categories' => [
                ['id' => $this->snacks->id, 'hidden' => false, 'color' => 'warning-tint', 'image' => null],
                ['id' => $this->drinks->id, 'hidden' => false, 'color' => 'primary-tint', 'image' => null],
            ],
            'quick_buttons' => [['type' => 'action', 'action' => 'hold'], ['type' => 'item', 'id' => $this->cola->id]],
            'keypad' => 'left',
            'customer_display' => ['welcome' => 'Karibu', 'show_lines' => true, 'show_second_currency' => false, 'show_logo' => true],
        ];

        foreach ($overrides as $key => $value) {
            $base[$key] = in_array($key, ['grid', 'products', 'customer_display'], true) ? [...$base[$key], ...$value] : $value;
        }

        return $base;
    }

    /** Save a draft at the scope and publish it (LAY-06); returns the publish answer. */
    private function publishAt(string $type, ?string $id, array $payload, ?array $headers = null): TestResponse
    {
        $saved = $this->postJson(self::URL, ['scope_type' => $type, 'scope_id' => $id, 'payload' => $payload], $headers ?? $this->headersFor());
        $saved->assertSuccessful();

        return $this->postJson(self::URL.'/'.$saved->json('data.id').'/publish', ['revision' => $saved->json('data.draft.revision')], $headers ?? $this->headersFor());
    }

    /** The till's `pos_layout` row, as a fresh pull gives it. */
    private function pulled(?string $token = null): array
    {
        $rows = $this->getJson('/api/v1/sync/pull?'.http_build_query(['entities' => ['pos_layout']]), $this->tillHeaders($token))
            ->assertOk()->json('entities.pos_layout.upserts');
        $this->assertCount(1, $rows);

        return $rows[0];
    }

    public function test_a_layout_is_checked_before_it_is_published(): void
    {
        $bad = $this->layout([
            'grid' => ['tablet_columns' => 9, 'phone_columns' => 1, 'tile_size' => 'huge'],
            'categories' => [['id' => $this->drinks->id, 'color' => '#ff0000']],
        ]);
        $saved = $this->postJson(self::URL, ['scope_type' => 'tenant', 'payload' => $bad], $this->headersFor())->assertCreated();
        $this->assertEqualsCanonicalizing(
            [['grid.tablet_columns', 'max'], ['grid.phone_columns', 'min'], ['grid.tile_size', 'enum'], ['categories.0.color', 'enum']],
            array_map(fn ($p) => [$p['path'], $p['code']], $saved->json('meta.problems')),
        );
        $this->postJson(self::URL.'/'.$saved->json('data.id').'/publish', ['revision' => $saved->json('data.draft.revision')], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'config_invalid');

        // What it names: unknown or archived items and categories, images that aren't the tenant's,
        // buttons that are both or neither, repeats, and more than 8 buttons.
        $this->inTenant(fn () => $this->snacks->archive());
        $missing = (string) Str::uuid7();
        $named = $this->layout([
            'products' => ['pinned' => [$this->cola->id, $missing]],
            'categories' => [['id' => $this->snacks->id, 'image' => null], ['id' => $this->drinks->id, 'image' => ['source' => 'brand_asset', 'id' => $missing]]],
            'quick_buttons' => [
                ['type' => 'action', 'action' => 'hold'], ['type' => 'action', 'action' => 'hold'],
                ['type' => 'item', 'id' => null], ['type' => 'category', 'id' => $this->drinks->id, 'action' => 'sales'],
            ],
        ]);
        $problems = $this->putJson(self::URL.'/'.$saved->json('data.id').'/draft', ['revision' => $saved->json('data.draft.revision'), 'payload' => $named], $this->headersFor())
            ->assertOk()->json('meta.problems');
        $this->assertEqualsCanonicalizing([
            ['quick_buttons.1', 'duplicate'],
            ['quick_buttons.2.id', 'required'],
            ['quick_buttons.3', 'pos_layout_button'],
            ['products.pinned.1', 'pos_layout_unknown_item'],
            ['categories.0.id', 'pos_layout_unknown_category'],
            ['categories.1.image', 'pos_layout_unknown_image'],
        ], array_map(fn ($p) => [$p['path'], $p['code']], $problems));
        $this->assertStringContainsString('archived', collect($problems)->firstWhere('code', 'pos_layout_unknown_category')['message']);

        $nine = array_fill(0, 9, ['type' => 'action', 'action' => 'hold']);
        $this->assertSame([['quick_buttons', 'max_items']], array_map(fn ($p) => [$p['path'], $p['code']], PosLayout::problems(['quick_buttons' => $nine])));
    }

    public function test_the_till_gets_the_layout_of_its_location_else_its_branch_company_or_tenant(): void
    {
        [, $tokenB] = $this->pairedTill($this->locationB, 'Till B');

        // Nothing published: the defaults, every category in name order.
        $fresh = $this->pulled();
        $this->assertNull($fresh['scope']);
        $this->assertSame(PosLayout::DEFAULTS['grid'], $fresh['layout']['grid']);
        $this->assertSame([$this->drinks->id, $this->snacks->id], array_column($fresh['layout']['categories'], 'id'));

        $this->publishAt('tenant', null, $this->layout(['grid' => ['tablet_columns' => 5]]))->assertOk();
        $this->publishAt('company', $this->acme->id, $this->layout(['grid' => ['tablet_columns' => 6]]))->assertOk();
        $this->assertSame(['company', 6], [$this->pulled()['scope']['type'], $this->pulled()['layout']['grid']['tablet_columns']]);

        $this->publishAt('branch', $this->branchB->id, $this->layout(['grid' => ['tablet_columns' => 3]]))->assertOk();
        $this->publishAt('location', $this->locationA->id, $this->layout())->assertOk();

        // A location's layout wins at that location only; Till B's branch has its own.
        $a = $this->pulled();
        $this->assertSame(['type' => 'location', 'id' => $this->locationA->id], $a['scope']);
        $this->assertSame(1, $a['version']);
        $this->assertSame([4, 'left', [$this->cola->id]], [$a['layout']['grid']['tablet_columns'], $a['layout']['keypad'], $a['layout']['products']['pinned']]);
        $this->assertSame([[$this->snacks->id, 'warning-tint'], [$this->drinks->id, 'primary-tint']], array_map(fn ($c) => [$c['id'], $c['color']], $a['layout']['categories']));
        $this->assertSame([['type' => 'action', 'action' => 'hold'], ['type' => 'item', 'id' => $this->cola->id]], $a['layout']['quick_buttons']);
        $this->assertSame('Karibu', $a['layout']['customer_display']['welcome']);
        $this->assertSame(['branch', 3], [$this->pulled($tokenB)['scope']['type'], $this->pulled($tokenB)['layout']['grid']['tablet_columns']]);

        // A draft never reaches a till.
        $this->postJson(self::URL, ['scope_type' => 'location', 'scope_id' => $this->locationA->id, 'revision' => null, 'payload' => $this->layout(['keypad' => 'right'])], $this->headersFor())->assertOk();
        $this->assertSame('left', $this->pulled()['layout']['keypad']);

        // RBAC-08: without the POS module there is no layout kind and no entity.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('pos'));
        $this->getJson(self::URL.'?scope_type=tenant', $this->headersFor())->assertNotFound();
    }

    public function test_upgrade_safety_new_categories_come_last_and_deleted_ones_are_skipped(): void
    {
        $this->publishAt('location', $this->locationA->id, $this->layout([
            'quick_buttons' => [['type' => 'category', 'id' => $this->snacks->id], ['type' => 'item', 'id' => $this->cola->id], ['type' => 'action', 'action' => 'customer']],
        ]))->assertOk();

        $this->inTenant(function () {
            $this->snacks->archive();
            $this->cola->archive();
            ItemCategory::create(['name' => 'Bakery']);
            // Another company's category never reaches this till.
            ItemCategory::create(['name' => 'Beta only', 'company_id' => $this->company('Beta')->id]);
        });

        $layout = $this->pulled()['layout'];
        $bakery = $this->inTenant(fn () => ItemCategory::where('name', 'Bakery')->sole()->id);
        $this->assertSame([[$this->drinks->id, false, 'primary-tint'], [$bakery, false, null]], array_map(fn ($c) => [$c['id'], $c['hidden'], $c['color']], $layout['categories']));
        $this->assertSame([], $layout['products']['pinned']);
        $this->assertSame([['type' => 'action', 'action' => 'customer']], $layout['quick_buttons']);
    }

    public function test_best_sellers_rank_the_items_sold_at_the_location(): void
    {
        $this->publishAt('tenant', null, $this->layout(['products' => ['pinned' => [], 'order' => 'best_sellers']]))->assertOk();
        $this->assertSame([], $this->pulled()['best_sellers']);

        $shift = $this->openShift();
        $this->ranges();
        $this->postJson('/api/v1/pos/sales', ['sales' => [$this->saleBody($shift, 1)]], $this->tillHeaders())->assertOk()->assertJsonPath('results.0.status', 'stored');

        $this->assertSame([$this->soap->id], $this->pulled()['best_sellers']);
    }

    public function test_who_may_design_the_layout(): void
    {
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $admin = $this->userWith('admin', Scope::tenant());
        $body = fn (string $type, ?string $id) => ['scope_type' => $type, 'scope_id' => $id, 'payload' => $this->layout()];

        $this->postJson(self::URL, $body('location', $this->locationA->id), $this->headersFor($cashier))->assertForbidden();
        $this->getJson(self::URL.'?scope_type=tenant', $this->headersFor($cashier))->assertForbidden();

        // A branch manager designs and publishes at their branch and its outlets, nowhere else.
        $this->publishAt('location', $this->locationA->id, $this->layout(), $this->headersFor($manager))->assertOk();
        $this->publishAt('branch', $this->branchA->id, $this->layout(), $this->headersFor($manager))->assertOk();
        $this->postJson(self::URL, $body('location', $this->locationB->id), $this->headersFor($manager))->assertForbidden();
        $this->postJson(self::URL, $body('tenant', null), $this->headersFor($manager))->assertForbidden();

        // The Admin template holds pos.layout.* (and the Owner everything).
        $this->publishAt('tenant', null, $this->layout(), $this->headersFor($admin))->assertOk();
        // Role and user scopes are not POS layout scopes.
        $this->postJson(self::URL, $body('role', $this->roles->get('cashier')->id), $this->headersFor())->assertUnprocessable();
    }

    public function test_other_tenants_never_meet_this_tenants_layouts_or_assets(): void
    {
        $other = $this->otherTenant();
        [$foreignItem, $foreignLocation] = $this->asTenant($other['user']->tenant_id, function () use ($other) {
            app(ModuleRegistry::class)->activate('pos');
            $uom = Uom::create(['code' => 'EA', 'name' => 'Each', 'kind' => 'count']);

            return [Item::create(['code' => 'X', 'name' => 'Theirs', 'type' => 'stock', 'base_uom_id' => $uom->id]), $other['location']];
        });

        // Tenant B's ids are unknown here: refused as a scope, reported as a problem in a payload.
        $this->postJson(self::URL, ['scope_type' => 'location', 'scope_id' => $foreignLocation->id, 'payload' => $this->layout()], $this->headersFor())->assertUnprocessable();
        $problems = $this->postJson(self::URL, ['scope_type' => 'tenant', 'payload' => $this->layout(['products' => ['pinned' => [$foreignItem->id]]])], $this->headersFor())
            ->assertCreated()->json('meta.problems');
        $this->assertSame([['products.pinned.0', 'pos_layout_unknown_item']], array_map(fn ($p) => [$p['path'], $p['code']], $problems));

        // Tenant B publishes a layout for its whole business: tenant A's till never gets it.
        $publish = $this->postJson(self::URL, ['scope_type' => 'tenant', 'payload' => ['keypad' => 'left']], $this->headersFor($other['user']))->assertCreated();
        $this->postJson(self::URL.'/'.$publish->json('data.id').'/publish', ['revision' => $publish->json('data.draft.revision')], $this->headersFor($other['user']))->assertOk();
        $this->assertSame(['right', null], [$this->pulled()['layout']['keypad'], $this->pulled()['scope']]);
        $this->assertSame(1, $this->inTenant(fn () => ConfigDocument::query()->count()));
    }

    public function test_a_till_reads_its_tenants_logos_but_not_favicons_or_other_tenants_assets(): void
    {
        Storage::fake('media');
        $upload = fn (string $kind, array $headers) => $this->post('/api/v1/branding/assets', ['kind' => $kind, 'file' => UploadedFile::fake()->image('a.png', 32, 32)], [...$headers, 'Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $logo = $upload('logo', $this->headersFor());
        $favicon = $upload('favicon', $this->headersFor());
        $other = $this->otherTenant();
        $theirs = $upload('logo', $this->headersFor($other['user']));

        $this->get('/api/v1/sync/brand-assets/'.$logo, $this->tillHeaders())->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/api/v1/sync/brand-assets/'.$favicon, $this->tillHeaders())->assertNotFound();
        $this->get('/api/v1/sync/brand-assets/'.$theirs, $this->tillHeaders())->assertNotFound();
        $this->get('/api/v1/sync/brand-assets/not-a-uuid', $this->tillHeaders())->assertNotFound();
        // People's tokens never reach device routes (TEN-05).
        $this->get('/api/v1/sync/brand-assets/'.$logo, [...$this->headersFor(), 'Accept' => 'application/json'])->assertForbidden();
        $this->assertTrue($this->inTenant(fn () => BrandAsset::query()->whereKey($logo)->exists()));
    }
}
