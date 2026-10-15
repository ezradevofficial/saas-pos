<?php

namespace Modules\Manufacturing\Tests\Concerns;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockLedger;
use Tests\Concerns\BuildsOrganisation;

/**
 * A tenant with Manufacturing active (BuildsOrganisation's Acme, base
 * currency KES, outlets A and B): the units EA (count), KG and G (weight,
 * flour also counted in grams) and L (volume); a bakery (bread made from
 * flour, yeast and water, giving bran as a by-product), a kitchen (a
 * burger made to order from a patty, a bun and a sauce, the sauce itself
 * a recipe of mayonnaise and chilli) and a planner (branch manager at
 * branch A).
 */
trait BuildsManufacturing
{
    use BuildsOrganisation;

    protected Uom $each;

    protected Uom $kg;

    protected Uom $gram;

    protected Uom $litre;

    protected Item $bread;

    protected Item $flour;

    protected Item $yeast;

    protected Item $water;

    protected Item $bran;

    protected Item $burger;

    protected Item $patty;

    protected Item $bun;

    protected Item $sauce;

    protected Item $mayo;

    protected Item $chilli;

    protected User $planner;

    protected function setUpManufacturing(bool $inventory = false): void
    {
        $this->setUpOrganisation();

        $this->inTenant(function () use ($inventory) {
            app(ModuleRegistry::class)->activate('manufacturing');

            if ($inventory) {
                app(ModuleRegistry::class)->activate('inventory');
            }

            $this->each = Uom::create(['code' => 'EA', 'name' => 'Each', 'kind' => 'count']);
            $this->kg = Uom::create(['code' => 'KG', 'name' => 'Kilogram', 'kind' => 'weight']);
            $this->gram = Uom::create(['code' => 'G', 'name' => 'Gram', 'kind' => 'weight']);
            $this->litre = Uom::create(['code' => 'L', 'name' => 'Litre', 'kind' => 'volume']);

            $stock = fn (string $code, string $name, Uom $uom) => Item::create(['code' => $code, 'name' => $name, 'type' => 'stock', 'base_uom_id' => $uom->id]);
            $this->bread = $stock('BREAD', 'Bread 800 g', $this->each);
            $this->flour = $stock('FLOUR', 'Wheat flour', $this->kg);
            ItemUom::create(['item_id' => $this->flour->id, 'uom_id' => $this->gram->id, 'factor' => '0.001']);
            $this->yeast = $stock('YEAST', 'Yeast', $this->kg);
            $this->water = $stock('WATER', 'Water', $this->litre);
            $this->bran = $stock('BRAN', 'Bran', $this->kg);
            $this->patty = $stock('PATTY', 'Beef patty', $this->each);
            $this->bun = $stock('BUN', 'Burger bun', $this->each);
            $this->mayo = $stock('MAYO', 'Mayonnaise', $this->litre);
            $this->chilli = $stock('CHILLI', 'Chilli', $this->kg);
            ItemUom::create(['item_id' => $this->chilli->id, 'uom_id' => $this->gram->id, 'factor' => '0.001']);
            $this->burger = Item::create(['code' => 'BURGER', 'name' => 'Burger', 'type' => 'kit', 'base_uom_id' => $this->each->id]);
            $this->sauce = Item::create(['code' => 'SAUCE', 'name' => 'House sauce', 'type' => 'non_stock', 'base_uom_id' => $this->litre->id]);
        });

        $this->planner = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
    }

    protected function api(string $method, string $uri, array $body = [], ?User $user = null): TestResponse
    {
        return $this->json($method, '/api/v1/'.$uri, $body, $this->headersFor($user));
    }

    /**
     * A BOM made and its first version published (now, or at $from).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{bom: array<string, mixed>, version: array<string, mixed>}
     */
    protected function publishedBom(Item $item, string $kind, array $lines, string $outputQty = '1', ?Uom $outputUom = null, int $labour = 0, ?string $from = null): array
    {
        $bom = $this->api('POST', 'manufacturing/boms', [
            'company_id' => $this->acme->id, 'item_id' => $item->id, 'kind' => $kind,
            'version' => ['output_qty' => $outputQty, 'output_uom_id' => $outputUom?->id, 'labour_minutes' => $labour, 'lines' => $lines],
        ])->assertCreated()->json('data');
        $published = $this->api('POST', "manufacturing/bom-versions/{$bom['draft_id']}/publish", array_filter(['effective_from' => $from]));
        $this->assertSame(200, $published->status(), (string) $published->getContent());
        $version = $published->json('data');

        return ['bom' => $bom, 'version' => $version];
    }

    /** The bakery's bread: 100 loaves from 50 kg flour (2% wastage), 0.5 kg yeast, 30 L water; 5 kg bran (10% of the cost); 120 minutes. */
    protected function breadBom(?string $from = null): array
    {
        return $this->publishedBom($this->bread, 'production', [
            ['item_id' => $this->flour->id, 'qty' => '50', 'wastage_percent' => '2'],
            ['item_id' => $this->yeast->id, 'qty' => '0.5'],
            ['item_id' => $this->water->id, 'qty' => '30'],
            ['kind' => 'by_product', 'item_id' => $this->bran->id, 'qty' => '5', 'cost_share_percent' => '10'],
        ], '100', $this->each, 120, $from);
    }

    /** The house sauce (a recipe of 1 L: 0.8 L mayonnaise, 50 g chilli) and the burger (a patty, a bun, 30 ml sauce exploded). */
    protected function burgerRecipe(): array
    {
        $this->publishedBom($this->sauce, 'recipe', [
            ['item_id' => $this->mayo->id, 'qty' => '0.8'],
            ['item_id' => $this->chilli->id, 'qty' => '50', 'uom_id' => $this->gram->id],
        ], '1', $this->litre);

        return $this->publishedBom($this->burger, 'recipe', [
            ['item_id' => $this->patty->id, 'qty' => '1'],
            ['item_id' => $this->bun->id, 'qty' => '1'],
            ['item_id' => $this->sauce->id, 'qty' => '0.03', 'explode' => true],
        ]);
    }

    /** A planned order at outlet A; @return array<string, mixed> */
    protected function plan(Item $item, string $qty, array $extra = [], ?User $by = null): array
    {
        return $this->api('POST', 'manufacturing/orders', ['location_id' => $this->locationA->id, 'item_id' => $item->id, 'qty' => $qty, ...$extra], $by)
            ->assertCreated()->json('data');
    }

    /** A released order; @return array<string, mixed> */
    protected function released(Item $item, string $qty): array
    {
        $order = $this->plan($item, $qty);

        return $this->api('POST', "manufacturing/orders/{$order['id']}/release")->assertOk()->assertJsonPath('data.status', 'released')->json('data');
    }

    /** @param array<string, string> $quantities item id => qty (base unit) */
    protected function issueAll(array $order, array $quantities): TestResponse
    {
        $lines = [];

        foreach ($order['materials'] as $material) {
            if (isset($quantities[$material['item']['id']])) {
                $lines[] = ['material_id' => $material['id'], 'qty' => $quantities[$material['item']['id']]];
            }
        }

        return $this->api('POST', "manufacturing/orders/{$order['id']}/issues", ['lines' => $lines]);
    }

    /** Goods received into Inventory at a location, at a total cost in KES minor units. */
    protected function receive(Item $item, string $qty, int $cost, ?string $location = null): void
    {
        $this->inTenant(fn () => app(StockLedger::class)->post(new Posting($this->acme->id, 'test.source', (string) Str::uuid7(), CarbonImmutable::now(), null, [
            new PostingLine($item->id, $location ?? $this->locationA->id, Movement::RECEIPT, Qty::of($qty), cost: BigInteger::of($cost), setsLastCost: true),
        ])));
    }

    protected function onHand(Item $item, ?string $location = null): string
    {
        return $this->inTenant(fn () => Qty::show((string) (Balance::query()->where('item_id', $item->id)->where('location_id', $location ?? $this->locationA->id)->value('qty') ?? '0')));
    }

    /** @return list<Movement> */
    protected function movements(string $sourceType): array
    {
        return $this->inTenant(fn () => Movement::query()->where('source_type', $sourceType)->orderBy('created_at')->orderBy('id')->get()->all());
    }
}
