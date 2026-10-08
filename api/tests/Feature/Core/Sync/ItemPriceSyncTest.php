<?php

namespace Tests\Feature\Core\Sync;

use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Tenancy\Models\Company;
use Tests\Concerns\BuildsTill;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NFR-04, MD-03 follow-up: item prices reach a till as the incremental
// entity `item_prices`: its company's lists only, usable prices only,
// tombstones when a price, its list, its item or its unit stops being
// usable, and nothing of another tenant.
class ItemPriceSyncTest extends TestCase
{
    use BuildsTill, RefreshTenantDatabase;

    private array $till;

    private Company $other;

    private PriceList $retail;

    private PriceList $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->other = $this->inTenant(fn () => $this->company('Other company'));
        $this->till = $this->pairTill($this->locationA);
        [$this->retail, $this->theirs] = $this->inTenant(function () {
            app(TenantCurrencies::class)->provisionFor($this->acme);

            return [
                PriceList::create(['company_id' => $this->acme->id, 'name' => 'Retail', 'currency' => 'KES', 'is_default' => true]),
                PriceList::create(['company_id' => $this->other->id, 'name' => 'Theirs', 'currency' => 'KES']),
            ];
        });
    }

    private function price(PriceList $list, Item $item, string $amount, array $extra = []): ItemPrice
    {
        return $this->inTenant(fn () => ItemPrice::create([
            'price_list_id' => $list->id, 'item_id' => $item->id, 'uom_id' => $item->base_uom_id,
            'amount_minor' => $amount, 'currency' => $list->currency, 'effective_from' => '2026-10-01', ...$extra,
        ]));
    }

    public function test_the_companys_prices_arrive_as_strings_and_never_another_companys(): void
    {
        $shared = $this->makeItem('SHARED');
        $own = $this->makeItem('OWN', $this->acme);
        $foreignItem = $this->makeItem('FOREIGN', $this->other);
        $a = $this->price($this->retail, $shared, '900719925474099312');
        $b = $this->price($this->retail, $own, '12450', ['effective_from' => '2026-12-01', 'min_quantity' => '2.5']);
        $theirList = $this->price($this->theirs, $shared, '100');
        $theirItem = $this->price($this->theirs, $foreignItem, '200');

        $pulled = $this->pullAll($this->till, 'item_prices');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_keys($pulled['upserts']));
        $this->assertNotContains($theirList->id, $pulled['seen']);
        $this->assertNotContains($theirItem->id, $pulled['seen']);
        $this->assertSame([
            'id' => $b->id, 'price_list_id' => $this->retail->id, 'item_id' => $own->id, 'uom_id' => $own->base_uom_id,
            'amount_minor' => '12450', 'currency' => 'KES', 'effective_from' => '2026-12-01', 'min_quantity' => '2.5',
        ], array_diff_key($pulled['upserts'][$b->id], ['updated_at' => true]));
        $this->assertSame('900719925474099312', $pulled['upserts'][$a->id]['amount_minor']);

        // Nothing changed: nothing comes back. A new amount comes back.
        $this->pull($this->till, ['item_prices'], ['item_prices' => $pulled['cursor']])->assertOk()->assertJsonPath('entities.item_prices.upserts', []);
        $this->inTenant(fn () => $a->fill(['amount_minor' => '5'])->save());
        $again = $this->pullAll($this->till, 'item_prices', $pulled['cursor']);
        $this->assertSame(['5'], array_column($again['upserts'], 'amount_minor'));
    }

    public function test_prices_that_stop_being_usable_become_tombstones_and_come_back(): void
    {
        $item = $this->makeItem('SODA');
        $box = $this->inTenant(function () use ($item) {
            $box = Uom::create(['code' => 'BOX', 'name' => 'Box', 'kind' => 'count']);
            ItemUom::create(['item_id' => $item->id, 'uom_id' => $box->id, 'factor' => '12']);

            return $box;
        });
        $each = $this->price($this->retail, $item, '100');
        $perBox = $this->price($this->retail, $item, '1100', ['uom_id' => $box->id]);
        $cursor = $this->pullAll($this->till, 'item_prices')['cursor'];

        // The price archived, then restored.
        $this->inTenant(fn () => $each->archive());
        $pulled = $this->pullAll($this->till, 'item_prices', $cursor);
        $this->assertSame([$each->id], $pulled['tombstones']);
        $this->inTenant(fn () => $each->restore());
        $pulled = $this->pullAll($this->till, 'item_prices', $pulled['cursor']);
        $this->assertSame([$each->id], array_keys($pulled['upserts']));

        // The box removed from the item, then added back.
        $this->inTenant(fn () => ItemUom::query()->where('item_id', $item->id)->get()->each->delete());
        $pulled = $this->pullAll($this->till, 'item_prices', $pulled['cursor']);
        $this->assertSame([$perBox->id], $pulled['tombstones']);
        $this->inTenant(fn () => ItemUom::create(['item_id' => $item->id, 'uom_id' => $box->id, 'factor' => '12']));
        $pulled = $this->pullAll($this->till, 'item_prices', $pulled['cursor']);
        $this->assertSame([$perBox->id], array_keys($pulled['upserts']));

        // The item archived, or moved to another company.
        $this->inTenant(fn () => $item->archive());
        $pulled = $this->pullAll($this->till, 'item_prices', $pulled['cursor']);
        $this->assertEqualsCanonicalizing([$each->id, $perBox->id], $pulled['tombstones']);
        $this->inTenant(fn () => $item->restore());
        $pulled = $this->pullAll($this->till, 'item_prices', $pulled['cursor']);
        $this->assertEqualsCanonicalizing([$each->id, $perBox->id], array_keys($pulled['upserts']));
        $this->inTenant(fn () => $item->fill(['company_id' => $this->other->id])->save());
        $pulled = $this->pullAll($this->till, 'item_prices', $pulled['cursor']);
        $this->assertEqualsCanonicalizing([$each->id, $perBox->id], $pulled['tombstones']);
        $this->inTenant(fn () => $item->fill(['company_id' => null])->save());
        $pulled = $this->pullAll($this->till, 'item_prices', $pulled['cursor']);
        $this->assertCount(2, $pulled['upserts']);

        // The list archived.
        $this->inTenant(fn () => $this->retail->archive());
        $pulled = $this->pullAll($this->till, 'item_prices', $pulled['cursor']);
        $this->assertEqualsCanonicalizing([$each->id, $perBox->id], $pulled['tombstones']);
    }

    public function test_another_tenants_prices_never_reach_the_device(): void
    {
        $other = $this->otherTenant();
        $foreign = $this->asTenant($other['user']->tenant_id, function () use ($other) {
            app(TenantCurrencies::class)->provisionFor($other['company']);
            $list = PriceList::create(['company_id' => $other['company']->id, 'name' => 'Their list', 'currency' => 'KES']);
            $item = Item::create(['code' => 'THEIRS', 'name' => 'Their item', 'type' => 'stock', 'base_uom_id' => Uom::create(['code' => 'EA', 'name' => 'Each', 'kind' => 'count'])->id]);

            return ItemPrice::create(['price_list_id' => $list->id, 'item_id' => $item->id, 'uom_id' => $item->base_uom_id, 'amount_minor' => '777', 'currency' => 'KES', 'effective_from' => '2026-10-01']);
        });
        $this->price($this->retail, $this->makeItem('MINE'), '100');

        $response = $this->pull($this->till, ['item_prices'])->assertOk();

        $this->assertStringNotContainsString($foreign->id, $response->getContent());
        $this->assertStringNotContainsString($foreign->price_list_id, $response->getContent());
        $this->assertCount(1, $response->json('entities.item_prices.upserts'));
    }
}
