<?php

namespace Modules\Manufacturing\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Modules\Manufacturing\Models\BomVersion;
use Modules\Manufacturing\Tests\Concerns\BuildsManufacturing;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MFG-02 to MFG-05, MFG-21, MFG-26: BOMs and recipes, versions with
// effective dates, lines (yield, wastage, units, by-products), explosion
// (multi-level, exact decimals), scope and audit.
class BomsTest extends TestCase
{
    use BuildsManufacturing, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpManufacturing();
    }

    public function test_a_production_bom_is_made_with_a_draft_and_published_from_now(): void
    {
        $made = $this->breadBom();

        $this->assertSame('production', $made['bom']['kind']);
        $this->assertSame('published', $made['version']['status']);
        $this->assertSame(1, $made['version']['version_no']);
        $this->assertSame('100', $made['version']['output_qty']);
        $this->assertSame(['component', 'component', 'component', 'by_product'], array_column($made['version']['lines'], 'kind'));
        $this->assertSame('2', $made['version']['lines'][0]['wastage_percent']);
        $this->assertSame('10', $made['version']['lines'][3]['cost_share_percent']);
        $this->api('GET', "manufacturing/boms/{$made['bom']['id']}")->assertOk()
            ->assertJsonPath('data.current_version.id', $made['version']['id'])
            ->assertJsonPath('data.draft_id', null)
            ->assertJsonCount(1, 'data.versions');
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::query()->where('action', 'manufacturing.bom.create')->count()));
    }

    public function test_the_item_type_must_fit_the_kind(): void
    {
        // MFG-02, decision 3: a recipe is for a made-to-order (non-stock) item; production makes stock.
        $this->api('POST', 'manufacturing/boms', ['company_id' => $this->acme->id, 'item_id' => $this->bread->id, 'kind' => 'recipe', 'version' => ['output_qty' => '1', 'lines' => []]])
            ->assertUnprocessable()->assertJsonPath('code', 'recipe_item_stocked');
        $this->api('POST', 'manufacturing/boms', ['company_id' => $this->acme->id, 'item_id' => $this->burger->id, 'kind' => 'production', 'version' => ['output_qty' => '1', 'lines' => []]])
            ->assertUnprocessable()->assertJsonPath('code', 'not_a_stock_item');
        $this->breadBom();
        $this->api('POST', 'manufacturing/boms', ['company_id' => $this->acme->id, 'item_id' => $this->bread->id, 'kind' => 'production', 'version' => ['output_qty' => '1', 'lines' => []]])
            ->assertUnprocessable()->assertJsonPath('code', 'bom_exists');
    }

    public function test_lines_are_checked_and_every_error_reported(): void
    {
        $response = $this->api('POST', 'manufacturing/boms', ['company_id' => $this->acme->id, 'item_id' => $this->bread->id, 'kind' => 'production', 'version' => ['output_qty' => '1', 'lines' => [
            ['item_id' => $this->bread->id, 'qty' => '1'],
            ['item_id' => $this->burger->id, 'qty' => '1'],
            ['item_id' => $this->flour->id, 'qty' => '1', 'uom_id' => $this->litre->id],
            ['item_id' => $this->yeast->id, 'qty' => '1', 'yield_percent' => '0'],
            ['item_id' => $this->flour->id, 'qty' => '1.2345', 'uom_id' => $this->gram->id],
            ['kind' => 'by_product', 'item_id' => $this->bran->id, 'qty' => '1', 'cost_share_percent' => '60'],
            ['kind' => 'by_product', 'item_id' => $this->yeast->id, 'qty' => '1', 'cost_share_percent' => '40'],
        ]]])->assertUnprocessable();

        $response->assertJsonPath('code', 'component_is_output');
        $this->assertSame(['version.lines.0.item_id', 'version.lines.1.item_id', 'version.lines.2.uom_id', 'version.lines.3.yield_percent', 'version.lines.4.qty', 'version.lines.6.cost_share_percent'], array_keys($response->json('errors')));
    }

    public function test_versions_take_effect_in_order_and_published_ones_never_change(): void
    {
        $first = $this->breadBom();
        $draft = $this->api('POST', "manufacturing/boms/{$first['bom']['id']}/versions")->assertCreated()->json('data');

        $this->assertSame(2, $draft['version_no']);
        $this->assertSame('draft', $draft['status']);
        $this->assertCount(4, $draft['lines']);
        $this->api('POST', "manufacturing/boms/{$first['bom']['id']}/versions")->assertConflict()->assertJsonPath('code', 'draft_exists');
        $this->api('PUT', "manufacturing/bom-versions/{$first['version']['id']}", ['output_qty' => '1', 'lines' => []])->assertConflict()->assertJsonPath('code', 'version_published');

        // MFG-03: a new version must start after the last one.
        $this->api('POST', "manufacturing/bom-versions/{$draft['id']}/publish", ['effective_from' => now()->subDay()->toIso8601String()])
            ->assertUnprocessable()->assertJsonPath('code', 'effective_from_order');

        $this->api('PUT', "manufacturing/bom-versions/{$draft['id']}", ['output_qty' => '100', 'output_uom_id' => $this->each->id, 'labour_minutes' => 100, 'lines' => [
            ['item_id' => $this->flour->id, 'qty' => '48'],
            ['item_id' => $this->yeast->id, 'qty' => '0.5'],
        ]])->assertOk()->assertJsonCount(2, 'data.lines');
        $next = now()->addMinutes(30);
        $this->api('POST', "manufacturing/bom-versions/{$draft['id']}/publish", ['effective_from' => $next->toIso8601String()])->assertOk();

        // The version in force follows the dates.
        $this->api('GET', "manufacturing/boms/{$first['bom']['id']}")->assertOk()->assertJsonPath('data.current_version.version_no', 1);
        $this->travelTo($next->copy()->addMinute());
        $this->api('GET', "manufacturing/boms/{$first['bom']['id']}")->assertOk()->assertJsonPath('data.current_version.version_no', 2);
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::query()->where('action', 'manufacturing.bom_version.lines_update')->count()));
    }

    public function test_a_multi_level_explosion_applies_yield_wastage_and_units_exactly(): void
    {
        $recipe = $this->burgerRecipe();

        // 3 burgers: a patty and a bun each; 0.03 L sauce exploded: 0.8 L mayonnaise and 50 g (0.05 kg) chilli per litre.
        $exploded = $this->api('GET', "manufacturing/bom-versions/{$recipe['version']['id']}/explode?qty=3")->assertOk()->json('data');

        $leaves = collect($exploded['leaves'])->mapWithKeys(fn ($leaf) => [$leaf['item']['code'] => $leaf['qty_base']])->all();
        $this->assertSame(['PATTY' => '3', 'BUN' => '3', 'MAYO' => '0.072', 'CHILLI' => '0.0045'], $leaves);
        $this->assertTrue($exploded['tree'][2]['explode']);
        $this->assertSame(['MAYO', 'CHILLI'], array_column(array_column($exploded['tree'][2]['children'], 'item'), 'code'));

        // MFG-05: need = qty × demand ÷ output × 100 ÷ yield × (1 + wastage ÷ 100), rounded once.
        $bread = $this->publishedBom($this->bread, 'production', [
            ['item_id' => $this->flour->id, 'qty' => '1', 'yield_percent' => '80', 'wastage_percent' => '5'],
            ['item_id' => $this->yeast->id, 'qty' => '7', 'uom_id' => $this->kg->id],
        ], '3', $this->each);
        $leaves = collect($this->api('GET', "manufacturing/bom-versions/{$bread['version']['id']}/explode?qty=1")->assertOk()->json('data.leaves'))->keyBy('item.code');

        $this->assertSame('0.4375', $leaves['FLOUR']['qty_base']);
        $this->assertSame('0.104167', $leaves['FLOUR']['loss_base']);
        $this->assertSame('2.333333', $leaves['YEAST']['qty_base']);
    }

    public function test_a_loop_through_exploded_recipes_is_refused_at_publishing(): void
    {
        $this->burgerRecipe();
        $sauce = $this->api('GET', 'manufacturing/boms?kind=recipe&item='.$this->sauce->id)->assertOk()->json('data.0');
        $draft = $this->api('POST', "manufacturing/boms/{$sauce['id']}/versions")->assertCreated()->json('data');
        $this->api('PUT', "manufacturing/bom-versions/{$draft['id']}", ['output_qty' => '1', 'lines' => [
            ['item_id' => $this->mayo->id, 'qty' => '0.8'],
            ['item_id' => $this->burger->id, 'qty' => '1', 'explode' => true],
        ]])->assertOk();

        $this->api('POST', "manufacturing/bom-versions/{$draft['id']}/publish", ['effective_from' => now()->addMinute()->toIso8601String()])
            ->assertUnprocessable()->assertJsonPath('code', 'bom_cycle');
        $this->assertSame('draft', $this->inTenant(fn () => BomVersion::query()->findOrFail($draft['id'])->status));
    }

    public function test_an_archived_bom_takes_no_new_version_and_lists_follow_the_status(): void
    {
        $made = $this->breadBom();
        $this->api('POST', "manufacturing/boms/{$made['bom']['id']}/archive")->assertOk()->assertJsonPath('data.archived_at', fn ($at) => $at !== null);
        $this->api('POST', "manufacturing/boms/{$made['bom']['id']}/versions")->assertConflict()->assertJsonPath('code', 'bom_archived');
        $this->api('GET', 'manufacturing/boms')->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', 'manufacturing/boms?status=archived')->assertOk()->assertJsonCount(1, 'data');
        // A new BOM of the item may be made once the old one is archived.
        $this->breadBom();
    }

    public function test_permissions_and_scope(): void
    {
        $made = $this->breadBom();
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $storekeeper = $this->userWith('storekeeper', Scope::location($this->locationA->id));

        // RBAC-04: a reader without the permission is not told the BOM exists.
        $this->api('GET', 'manufacturing/boms', [], $cashier)->assertForbidden();
        $this->api('GET', "manufacturing/boms/{$made['bom']['id']}", [], $cashier)->assertNotFound();
        // The storekeeper reads BOMs but does not change them.
        $this->api('GET', "manufacturing/boms/{$made['bom']['id']}", [], $storekeeper)->assertOk()->assertJsonPath('data.can.manage', false);
        $this->api('POST', "manufacturing/boms/{$made['bom']['id']}/versions", [], $storekeeper)->assertForbidden();
        $this->api('POST', 'manufacturing/boms', ['company_id' => $this->acme->id, 'item_id' => $this->burger->id, 'kind' => 'recipe', 'version' => ['output_qty' => '1', 'lines' => []]], $storekeeper)->assertForbidden();

        // Another tenant's BOM does not exist there (RLS).
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('manufacturing'));
        $this->getJson("/api/v1/manufacturing/boms/{$made['bom']['id']}", $this->headersFor($other['user']))->assertNotFound();
        $this->getJson('/api/v1/manufacturing/boms', $this->headersFor($other['user']))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_routes_are_closed_without_the_module(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('manufacturing'));

        $this->api('GET', 'manufacturing/boms')->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }

    public function test_a_bom_published_later_is_not_in_force_before_its_date(): void
    {
        $made = $this->breadBom(CarbonImmutable::now()->addDay()->toIso8601String());

        $this->api('GET', "manufacturing/boms/{$made['bom']['id']}")->assertOk()->assertJsonPath('data.current_version', null);
        $this->api('POST', 'manufacturing/orders', ['location_id' => $this->locationA->id, 'item_id' => $this->bread->id, 'qty' => '10'])
            ->assertUnprocessable()->assertJsonPath('code', 'no_bom_in_force');
    }
}
