<?php

namespace Modules\Restaurant\Tests\Concerns;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Modules\POS\Tests\Concerns\BuildsPos;
use Modules\Restaurant\Models\Area;
use Modules\Restaurant\Models\Modifier;
use Modules\Restaurant\Models\ModifierGroup;
use Modules\Restaurant\Models\Station;
use Modules\Restaurant\Models\StationRoute;
use Modules\Restaurant\Models\Table;

/**
 * A tenant with POS and Restaurant active (BuildsPos' Acme, KES, till 1 at
 * location A): a main hall with tables T1 to T3 and a terrace table T4, a
 * kitchen (default) and a bar station with the food and drink categories
 * routed, a burger (food, 12.5 % test tax via the goods category), a beer
 * (drink), a non-stock service charge item and tip item, a waiter and a
 * kitchen user at location A, and a branch manager at branch A.
 */
trait BuildsRestaurant
{
    use BuildsPos;

    protected Area $hall;

    protected Table $t1;

    protected Table $t2;

    protected Table $t3;

    protected Station $kitchen;

    protected Station $bar;

    protected ItemCategory $food;

    protected ItemCategory $drinks;

    protected Item $burger;

    protected Item $beer;

    protected Item $serviceItem;

    protected Item $tipItem;

    protected User $waiter;

    protected User $cook;

    protected User $manager;

    protected function setUpRestaurant(): void
    {
        $this->setUpPos();

        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('restaurant');
            $this->food = ItemCategory::create(['name' => 'Food']);
            $this->drinks = ItemCategory::create(['name' => 'Drinks']);
            $make = fn (string $code, string $name, ItemCategory $category) => Item::create([
                'code' => $code, 'name' => $name, 'type' => 'non_stock', 'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id, 'category_id' => $category->id,
            ]);
            $this->burger = $make('BURGER', 'Burger', $this->food);
            $this->beer = $make('BEER', 'Beer', $this->drinks);
            $this->serviceItem = Item::create(['code' => 'SVC', 'name' => 'Service charge', 'type' => 'non_stock', 'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id]);
            $this->tipItem = Item::create(['code' => 'TIP', 'name' => 'Tip', 'type' => 'non_stock', 'base_uom_id' => $this->each->id]);

            $this->hall = Area::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'location_id' => $this->locationA->id, 'name' => 'Main hall']);
            $table = fn (string $name) => Table::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'location_id' => $this->locationA->id, 'area_id' => $this->hall->id, 'name' => $name, 'seats' => 4]);
            [$this->t1, $this->t2, $this->t3] = [$table('T1'), $table('T2'), $table('T3')];

            $station = fn (string $name, string $kind, bool $default) => Station::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'location_id' => $this->locationA->id, 'name' => $name, 'kind' => $kind, 'is_default' => $default]);
            $this->kitchen = $station('Kitchen', 'kitchen', true);
            $this->bar = $station('Bar', 'bar', false);
            StationRoute::create(['location_id' => $this->locationA->id, 'station_id' => $this->kitchen->id, 'item_category_id' => $this->food->id]);
            StationRoute::create(['location_id' => $this->locationA->id, 'station_id' => $this->bar->id, 'item_category_id' => $this->drinks->id]);
        });

        $this->waiter = $this->userWith('waiter', Scope::location($this->locationA->id));
        $this->cook = $this->userWith('kitchen', Scope::location($this->locationA->id));
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
    }

    protected function api(string $method, string $uri, array $body = [], ?User $user = null): TestResponse
    {
        return $this->json($method, '/api/v1/'.$uri, $body, $this->headersFor($user));
    }

    /** Another tenant with the Restaurant module active, so only isolation (not the module gate) refuses it. */
    protected function otherRestaurantTenant(): array
    {
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('restaurant'));

        return $other;
    }

    /** A modifier of the company in KES (a group of its own); returns [id, price_minor]. @return array{0: string, 1: string} */
    protected function modifier(string $name = 'Extra cheese', string $price = '5000', string $currency = 'KES'): array
    {
        return $this->inTenant(function () use ($name, $price, $currency) {
            $group = ModifierGroup::create(['company_id' => $this->acme->id, 'name' => "Group {$name}", 'min_select' => 0, 'max_select' => 2, 'currency' => $currency]);
            $modifier = Modifier::create(['group_id' => $group->id, 'name' => $name, 'price_minor' => $price]);

            return [$modifier->id, $price];
        });
    }

    /** One order line as the till sends it (a burger of KES 800.00, test tax 12.5 % exclusive). */
    protected function orderLine(array $overrides = []): array
    {
        return array_replace([
            'id' => $this->id(),
            'item_id' => $this->burger->id,
            'name' => 'Burger',
            'qty' => '1',
            'unit_price_minor' => '80000',
            'tax_code_id' => $this->vat->id,
            'tax_rate' => '12.5',
            'tax_inclusive' => false,
            'course' => 1,
            'seat' => null,
            'note' => null,
            'modifiers' => [],
            'created_at' => CarbonImmutable::now()->subMinutes(10)->toIso8601String(),
        ], $overrides);
    }

    /** An order record for a table (or a tab with $overrides['kind'] = 'tab'). */
    protected function orderRecord(array $lines = [], array $overrides = []): array
    {
        $now = CarbonImmutable::now()->subMinutes(10)->toIso8601String();

        return array_replace([
            'id' => $this->id(),
            'kind' => 'table',
            'table_id' => $this->t1->id,
            'tab_name' => null,
            'customer_id' => null,
            'waiter_id' => $this->waiter->id,
            'covers' => 2,
            'currency' => 'KES',
            'opened_at' => $now,
            'updated_at' => $now,
            'updated_by' => $this->waiter->id,
            'lines' => $lines,
            'resends' => [],
        ], $overrides);
    }

    /** A line sent to the kitchen now. */
    protected function sent(array $line, int $round = 1): array
    {
        return array_replace($line, ['sent_at' => CarbonImmutable::now()->subMinutes(5)->toIso8601String(), 'sent_by' => $this->waiter->id, 'round' => $round]);
    }

    protected function sync(array $orders, ?string $token = null): TestResponse
    {
        return $this->postJson('/api/v1/restaurant/orders/sync', ['orders' => $orders], $this->tillHeaders($token));
    }

    /** Stores one order record and returns the result. @return array<string, mixed> */
    protected function store(array $record): array
    {
        return $this->sync([$record])->assertOk()->assertJsonPath('results.0.status', 'stored')->json('results.0');
    }

    /** A voided-line `removed` block by $user. */
    protected function removed(User $user, string $reason = 'Wrong dish', array $extra = []): array
    {
        return array_replace(['at' => CarbonImmutable::now()->subMinute()->toIso8601String(), 'by' => $user->id, 'reason' => $reason, 'actor_proof' => $this->actorProof($user->id)], $extra);
    }
}
