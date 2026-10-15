<?php

namespace Modules\Accounting\Tests;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\ModuleRegistry;
use Illuminate\Support\Facades\Event;
use Modules\Accounting\Integrations\Integrations;
use Modules\Accounting\Listeners\PostModuleEvent;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\PostingException;
use Modules\Accounting\Tests\Concerns\BuildsIntegrations;
use Modules\Inventory\Listeners\MoveStockForManufacturing;
use Modules\Manufacturing\Events\MaterialsMoved;
use Modules\Manufacturing\Events\ProductionValued;
use Modules\Manufacturing\Models\Setting;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * ACC-33 (docs/modules/manufacturing.md MFG-20): a production order posts
 * its materials into work in progress, its receipts out of it with the
 * labour and overhead they absorbed, and what is left at close to
 * production variances; a sale's recipe ingredients post to cost of sales.
 * One balanced journal per document, idempotent; Inventory's own stock
 * moves for Manufacturing post nothing; the KE and CD templates carry the
 * default rules.
 */
class ManufacturingPostingTest extends TestCase
{
    use BuildsIntegrations, RefreshTenantDatabase;

    private Item $flour;

    private Item $bread;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIntegrations();

        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('manufacturing');
            $kg = Uom::create(['code' => 'KG', 'name' => 'Kilogram', 'kind' => 'weight']);
            $this->flour = Item::create(['code' => 'FLOUR', 'name' => 'Flour', 'type' => 'stock', 'base_uom_id' => $kg->id]);
            $this->bread = Item::create(['code' => 'BREAD', 'name' => 'Bread', 'type' => 'stock', 'base_uom_id' => $this->each->id]);
            // KES 600.00 an hour of labour, 300.00 of overhead.
            Setting::create(['company_id' => $this->acme->id, 'labour_rate_minor' => 60000, 'overhead_rate_minor' => 30000, 'currency' => 'KES']);
        });

        // 10 kg of flour at KES 50.00 a kg.
        $this->postJson('/api/v1/inventory/opening-stock', ['item_id' => $this->flour->id, 'location_id' => $this->locationA->id, 'qty' => '10', 'unit_cost' => '50'], $this->headersFor())->assertCreated();
        // 10 loaves from 5 kg of flour in an hour.
        $bom = $this->postJson('/api/v1/manufacturing/boms', ['company_id' => $this->acme->id, 'item_id' => $this->bread->id, 'kind' => 'production', 'version' => [
            'output_qty' => '10', 'labour_minutes' => 60, 'lines' => [['item_id' => $this->flour->id, 'qty' => '5']],
        ]], $this->headersFor())->assertCreated()->json('data');
        $this->postJson("/api/v1/manufacturing/bom-versions/{$bom['draft_id']}/publish", [], $this->headersFor())->assertOk();
    }

    public function test_an_order_posts_wip_in_and_out_and_clears_it_at_close(): void
    {
        $order = $this->postJson('/api/v1/manufacturing/orders', ['location_id' => $this->locationA->id, 'item_id' => $this->bread->id, 'qty' => '10'], $this->headersFor())->assertCreated()->json('data');
        $this->postJson("/api/v1/manufacturing/orders/{$order['id']}/release", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/manufacturing/orders/{$order['id']}/issues", ['lines' => [['material_id' => $order['materials'][0]['id'], 'qty' => '5']]], $this->headersFor())->assertCreated();
        // 8 good: 8/10 of the flour (20,000), 48 minutes of labour (48,000) and overhead (24,000).
        $this->postJson("/api/v1/manufacturing/orders/{$order['id']}/reports", ['qty_good' => '8'], $this->headersFor())->assertCreated();
        $closed = $this->postJson("/api/v1/manufacturing/orders/{$order['id']}/close", [], $this->headersFor())->assertOk()->json('data');
        $this->assertSame('5000', $closed['variance']['amount_minor']);

        $this->assertSame(['1300' => [0, 25000], '1310' => [25000, 0]], $this->linesOf($this->journalsOf('manufacturing.materials')[0]));
        $this->assertSame(['1300' => [92000, 0], '1310' => [0, 20000], '5410' => [0, 48000], '5420' => [0, 24000]], $this->linesOf($this->journalsOf('manufacturing.production')[0]));
        $this->assertSame(['1310' => [0, 5000], '5400' => [5000, 0]], $this->linesOf($this->journalsOf('manufacturing.variance')[0]));

        // WIP is empty again, the stock account is the valuation, nothing posted twice or as an exception.
        $this->assertSame(0, $this->balance('1310'));
        $this->assertSame($this->valuation(), $this->balance('1300'));
        $this->assertBalanced();
        $this->assertSame([], $this->journalsOf('inventory.other'));
        $this->assertSame(0, $this->inTenant(fn () => PostingException::query()->where('status', 'open')->count()));
        $journal = $this->journalsOf('manufacturing.production')[0];
        $this->assertSame($order['id'], $journal->source_link['order_id']);
        $this->assertStringStartsWith('Production received PR-', (string) $journal->memo);

        // The backfill replays Manufacturing's valued documents: nothing posts twice.
        $journals = $this->inTenant(fn () => Journal::query()->count());
        $this->artisan('accounting:backfill', ['--from' => now()->subDay()->toDateString()])->assertSuccessful();
        $this->assertSame($journals, $this->inTenant(fn () => Journal::query()->count()));
    }

    public function test_recipe_ingredients_post_to_cost_of_sales_once_and_a_return_reverses_them(): void
    {
        $event = fn (string $id, string $value, string $variance = '0') => new ProductionValued($this->owner->tenant_id, ProductionValued::BACKFLUSH, $id, 'R-L01-000001',
            null, null, $this->acme->id, $this->branchA->id, $this->locationA->id, now()->toIso8601String(), 'KES',
            [['line_id' => $this->id(), 'item_id' => $this->flour->id, 'value_minor' => $value, 'cost_variance_minor' => $variance]], documentKind: 'manufacturing.backflush');
        $consumed = $event($this->id(), '-12345', '-345');

        $this->assertTrue($this->inTenant(fn () => app(PostModuleEvent::class)->shouldQueue($consumed)));
        app(PostModuleEvent::class)->handle($consumed);
        app(PostModuleEvent::class)->handle($consumed);
        $returned = $event($this->id(), '12345');
        $this->inTenant(fn () => app(Integrations::class)->handle($returned));
        $this->assertSame([], $this->inTenant(fn () => PostingException::query()->pluck('error_code')->all()));

        $journals = collect($this->journalsOf('manufacturing.backflush'))->keyBy('source_id');
        [$out, $back] = [$journals[$consumed->documentId], $journals[$returned->documentId]];
        // Stock by value less cost variance (12,000); cost of sales takes the value (12,345) less the variance (345).
        $this->assertSame(['1300' => [0, 12000], '5100' => [12345, 345]], $this->linesOf($out));
        $this->assertSame(['1300' => [12345, 0], '5100' => [0, 12345]], $this->linesOf($back));
        $this->assertCount(2, $this->journalsOf('manufacturing.backflush'));
        $this->assertBalanced();

        // Review fix 6 (the stage 7B decision): Manufacturing switched off still posts what it
        // valued (Inventory moved it); only Accounting being off stops the posting.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('manufacturing'));
        $this->assertTrue($this->inTenant(fn () => app(PostModuleEvent::class)->shouldQueue($consumed)));
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('accounting'));
        $this->assertFalse($this->inTenant(fn () => app(PostModuleEvent::class)->shouldQueue($consumed)));
    }

    public function test_a_document_in_flight_posts_after_manufacturing_is_switched_off(): void
    {
        // Review fix 6: the issue is made while Manufacturing is on; Inventory moves it after
        // Manufacturing is switched off. Stock left, so the ledger follows while Accounting is on.
        $order = $this->postJson('/api/v1/manufacturing/orders', ['location_id' => $this->locationA->id, 'item_id' => $this->bread->id, 'qty' => '10'], $this->headersFor())->assertCreated()->json('data');
        $this->postJson("/api/v1/manufacturing/orders/{$order['id']}/release", [], $this->headersFor())->assertOk();
        Event::fake([MaterialsMoved::class]);
        $this->postJson("/api/v1/manufacturing/orders/{$order['id']}/issues", ['lines' => [['material_id' => $order['materials'][0]['id'], 'qty' => '5']]], $this->headersFor())->assertCreated();
        $issued = Event::dispatched(MaterialsMoved::class)->map(fn ($call) => $call[0])->first();

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('manufacturing'));
        $this->inTenant(fn () => app(MoveStockForManufacturing::class)->handle($issued));

        $this->assertSame(['1300' => [0, 25000], '1310' => [25000, 0]], $this->linesOf($this->journalsOf('manufacturing.materials')[0]));
        $this->assertSame($this->valuation(), $this->balance('1300'));
        $this->assertBalanced();
    }

    public function test_the_ke_and_cd_templates_map_every_manufacturing_field_to_a_postable_account(): void
    {
        foreach (['KE' => ['1310', '5400', '5410', '5420'], 'CD' => ['341', '781', '658']] as $country => $codes) {
            $chart = json_decode((string) file_get_contents(base_path("country-packs/{$country}/accounts.json")), true);
            $postable = collect($chart['accounts'])->where('postable', true)->pluck('code')->all();

            foreach ($codes as $code) {
                $this->assertContains($code, $postable, "{$country} {$code}");
            }

            foreach (['manufacturing.materials', 'manufacturing.production', 'manufacturing.variance', 'manufacturing.backflush'] as $type) {
                $this->assertArrayHasKey($type, $chart['posting_rules'], "{$country} {$type}");

                foreach ($chart['posting_rules'][$type] as $line) {
                    $this->assertContains($line['account'], $postable, "{$country} {$type} {$line['field']}");
                }
            }
        }
    }
}
