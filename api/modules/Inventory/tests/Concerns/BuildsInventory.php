<?php

namespace Modules\Inventory\Tests\Concerns;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use App\Core\Rbac\ModuleRegistry;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\CostState;
use Modules\Inventory\Models\InventorySetting;
use Modules\Inventory\Models\ItemSetting;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockLedger;
use Tests\Concerns\BuildsOrganisation;

/**
 * A tenant with the Inventory module active (BuildsOrganisation's Acme,
 * base currency KES, with outlets A and B), the units EA (count), BOX
 * (count) and KG (weight), and three stock items: soap (EA, boxes of 12),
 * milk (batch tracked with expiry) and a phone (serial tracked), plus a
 * service that never moves stock.
 */
trait BuildsInventory
{
    use BuildsOrganisation;

    protected Uom $each;

    protected Uom $box;

    protected Uom $kg;

    protected Item $soap;

    protected Item $milk;

    protected Item $phone;

    protected Item $sugar;

    protected Item $service;

    protected function setUpInventory(): void
    {
        $this->setUpOrganisation();

        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('inventory');

            $this->each = Uom::create(['code' => 'EA', 'name' => 'Each', 'kind' => 'count']);
            $this->box = Uom::create(['code' => 'BOX', 'name' => 'Box', 'kind' => 'count']);
            $this->kg = Uom::create(['code' => 'KG', 'name' => 'Kilogram', 'kind' => 'weight']);

            $this->soap = Item::create(['code' => 'SOAP', 'name' => 'Soap', 'type' => 'stock', 'base_uom_id' => $this->each->id]);
            ItemUom::create(['item_id' => $this->soap->id, 'uom_id' => $this->box->id, 'factor' => '12']);
            $this->milk = Item::create(['code' => 'MILK', 'name' => 'Milk', 'type' => 'stock', 'base_uom_id' => $this->each->id]);
            ItemSetting::create(['item_id' => $this->milk->id, 'tracking' => ItemSetting::BATCH, 'track_expiry' => true]);
            $this->phone = Item::create(['code' => 'PHONE', 'name' => 'Phone', 'type' => 'stock', 'base_uom_id' => $this->each->id]);
            ItemSetting::create(['item_id' => $this->phone->id, 'tracking' => ItemSetting::SERIAL]);
            $this->sugar = Item::create(['code' => 'SUGAR', 'name' => 'Sugar', 'type' => 'stock', 'base_uom_id' => $this->kg->id]);
            $this->service = Item::create(['code' => 'DELIVERY', 'name' => 'Delivery', 'type' => 'service', 'base_uom_id' => $this->each->id]);
        });
    }

    protected function costing(string $method, bool $allowNegative = true): void
    {
        $this->inTenant(fn () => InventorySetting::query()->updateOrCreate(['company_id' => $this->acme->id], ['costing_method' => $method, 'allow_negative' => $allowNegative]));
    }

    /**
     * Post one line straight to the ledger (as a source document would).
     *
     * @return list<Movement>
     */
    protected function move(Item $item, string $kind, string $qty, ?int $cost = null, ?string $location = null, array $extra = [], bool $manual = true, ?string $source = null): array
    {
        return $this->inTenant(fn () => app(StockLedger::class)->post(new Posting(
            $this->acme->id,
            $extra['source_type'] ?? 'test.source',
            $source ?? (string) Str::uuid7(),
            CarbonImmutable::now(),
            null,
            [new PostingLine(
                $item->id,
                array_key_exists('location', $extra) ? $extra['location'] : ($location ?? $this->locationA->id),
                $kind,
                Qty::of($qty),
                cost: $cost === null ? null : BigInteger::of($cost),
                setsLastCost: $cost !== null,
                batchId: $extra['batch_id'] ?? null,
                reversesMovementId: $extra['reverses'] ?? null,
                fefo: $extra['fefo'] ?? false,
            )],
            $manual,
        )));
    }

    protected function onHand(Item $item, ?string $location = null): string
    {
        return $this->inTenant(fn () => Qty::show((string) (Balance::query()->where('item_id', $item->id)->where('location_id', $location ?? $this->locationA->id)->value('qty') ?? '0')));
    }

    /** @return array{qty: string, value: string} the company's quantity and value of $item */
    protected function costState(Item $item): array
    {
        $state = $this->inTenant(fn () => CostState::query()->where('company_id', $this->acme->id)->where('item_id', $item->id)->first());

        return ['qty' => Qty::show($state?->qty ?? '0'), 'value' => (string) ($state?->value_minor ?? '0')];
    }
}
