<?php

namespace Modules\POS\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\Rbac\Models\LimitRule;
use App\Core\Rbac\Scope;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\POS\Models\CashMovement;
use Modules\POS\Models\NumberRange;
use Modules\POS\Models\Refund;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleLine;
use Modules\POS\Models\SaleVoid;
use Modules\POS\Models\Shift;
use Modules\POS\Models\ShiftBalance;
use Modules\POS\Tests\Concerns\BuildsPos;
use Modules\POS\Tests\Concerns\SimulatesDevice;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NFR-04, NUM-02, POS-04, POS-05, POS-09, AUTH-08 (ADR 004, phase 4 Task 7):
// one paired till, driven only through the device endpoints, bootstraps and
// pulls, then sells for 7 days without a connection while the server
// changes prices, items, a tax rate and an exchange rate and archives an
// item. On reconnect it pushes its whole backlog (7 shifts, 301 sales from
// its pre-allocated range, cash movements, a void and a refund) in the
// engine's order, then pulls again from the cursors it froze with.
class OfflineWeekTest extends TestCase
{
    use BuildsExchangeRates, BuildsPos, RefreshTenantDatabase, SimulatesDevice;

    private const DAYS = 7;

    private const SALES_PER_DAY = 43;

    private const FLOAT = 500000;

    private User $cashier;

    private User $manager;

    private array $device;

    /** @var array<string, Item> by code */
    private array $items = [];

    public function test_a_till_sells_for_seven_days_offline_and_everything_lands_once_after_reconnect(): void
    {
        $start = CarbonImmutable::parse('2026-11-02T04:00:00Z');
        $this->travelTo($start);
        $this->setUpWorld();

        // Day 0, online: bootstrap, number ranges, a full pull. Then the device's copy is frozen.
        $entities = $this->bootstrapDevice($this->device);
        $receipts = $this->ranges('pos.receipt', token: $this->device['token'])->assertOk()->json('data.0');
        $refunds = $this->ranges('pos.refund', token: $this->device['token'])->assertOk()->json('data.0');
        $this->assertSame([1, 500], [$receipts['from'], $receipts['to']]);
        $initial = $this->pullAllEntities($this->device, $entities, [], 50);
        $frozen = $initial['cursors'];
        $copy = [];
        foreach ($entities as $key) {
            $copy[$key] = $this->applyPages([], $initial['pages'][$key]);
        }
        $catalogue = $this->catalogueFrom($copy);
        $this->assertCount(10, $catalogue, 'the device holds 10 priced items');
        $usdRate = collect($copy['exchange_rates'])->first(fn ($r) => $r['base'] === 'USD' && $r['quote'] === 'KES')['mid'];
        $taxRate = collect($copy['tax_codes'])->firstWhere('code', 'VAT_T')['rates'][0]['rate'];
        $this->assertSame(['130.00000000', '12.5000'], [$usdRate, $taxRate]);

        // Offline from here. The server changes master data during the week.
        $soapPrice = $archivedPrice = null;
        $this->travelTo($start->addDays(2)->addHours(6));
        $this->inTenant(fn () => $this->items['ITEM02']->forceFill(['name' => 'Item ITEM02 renamed'])->save());
        $this->travelTo($start->addDays(3)->addHours(-3));
        $this->inTenant(function () use (&$soapPrice, &$archivedPrice) {
            $archivedPrice = ItemPrice::where('item_id', $this->items['ITEM09']->id)->sole();
            $this->items['ITEM09']->archive();
            $soapPrice = ItemPrice::create(['price_list_id' => $this->retail->id, 'item_id' => $this->soap->id, 'uom_id' => $this->each->id, 'amount_minor' => '60750', 'currency' => 'KES', 'effective_from' => '2026-11-05']);
        });
        $this->travelTo($start->addDays(4)->addHours(-3));
        $this->inTenant(fn () => TaxRate::create(['tax_code_id' => $this->vat->id, 'rate' => '14', 'effective_from' => '2026-11-06']));
        $this->travelTo($start->addDays(5)->addHours(-2));
        $this->rate('USD', 'KES', '131', at: $start->addDays(5)->addHours(-2)->toIso8601String());
        $newItem = $this->item('ITEM10', '13500');

        // The device's week, written to its outbox in the order it happened (engine order).
        $sales = [];
        $expectedFlags = [];
        $cash = [];
        $seq = $receipts['from'];
        $voided = null;
        $refunded = null;

        for ($day = 0; $day < self::DAYS; $day++) {
            $dayStart = $start->addDays($day);
            $date = $dayStart->setTimezone('Africa/Nairobi')->toDateString();
            $shift = $this->id();
            $opening = [...$this->shiftBody(['id' => $shift, 'opened_by_id' => $this->cashier->id, 'opened_at' => $dayStart->addHour()->toIso8601String()]), 'closing' => null];
            $this->enqueue('pos.shifts', $opening);
            $cash[$shift] = ['KES' => self::FLOAT, 'USD' => 0];

            for ($j = 0; $j < self::SALES_PER_DAY; $j++) {
                $n = $day * self::SALES_PER_DAY + $j;
                $at = $dayStart->addHour()->addMinutes(10 + 12 * $j);
                $sale = $this->deviceSale($shift, $seq++, $receipts['id'], $at, $n, $catalogue, $taxRate, $usdRate);
                $sales[$sale['id']] = $sale;
                $this->enqueue('pos.sales', $sale);
                $expectedFlags[$sale['id']] = $this->flagsExpected($sale, $date, $at, $start);
                $this->countCash($cash[$shift], $sale, +1);

                if ($day === 1 && $j === 10) {
                    foreach ([['pay_in', 100000, 'Float top-up'], ['pay_out', 50000, 'Cleaning supplies']] as [$kind, $amount, $reason]) {
                        $id = $this->id();
                        $when = $at->addMinutes(2)->toIso8601String();
                        $this->enqueue('pos.cash_movements', [
                            'id' => $id, 'shift_id' => $shift, 'user_id' => $this->cashier->id, 'kind' => $kind, 'currency' => 'KES',
                            'amount_minor' => (string) $amount, 'reason' => $reason, 'occurred_at' => $when,
                            'override' => $this->offlineOverride($this->device, $this->manager->id, $this->cashier->id, 'pos.cash.move', $id, $when),
                        ]);
                        $cash[$shift]['KES'] += $kind === 'pay_in' ? $amount : -$amount;
                    }
                }

                if ($day === 3 && $j === 20) {
                    $voided = array_values($sales)[$n - 15];
                    $id = $this->id();
                    $when = $at->addMinutes(1)->toIso8601String();
                    $this->enqueue('pos.voids', [
                        'id' => $id, 'sale_id' => $voided['id'], 'voided_by_id' => $this->cashier->id, 'voided_at' => $when, 'reason' => 'Wrong item',
                        'override' => $this->offlineOverride($this->device, $this->manager->id, $this->cashier->id, 'pos.sale.void', $id, $when),
                    ]);
                    $this->countCash($cash[$shift], $voided, -1);
                }

                if ($day === 5 && $j === 25) {
                    $refunded = array_values($sales)[$n - 17];
                    $line = $refunded['lines'][0];
                    $id = $this->id();
                    $when = $at->addMinutes(1)->toIso8601String();
                    $this->enqueue('pos.refunds', [
                        'id' => $id, 'sale_id' => $refunded['id'], 'shift_id' => $shift, 'cashier_id' => $this->cashier->id,
                        'receipt_seq' => $refunds['from'], 'receipt_number' => sprintf('RF-L01-%06d', $refunds['from']), 'number_range_id' => $refunds['id'],
                        'refunded_at' => $when, 'reason' => 'Damaged', 'total_minor' => $line['unit_price_minor'],
                        'lines' => [['id' => $this->id(), 'sale_line_id' => $line['id'], 'qty' => '1']],
                        'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => $line['unit_price_minor'], 'amount_in_sale_minor' => $line['unit_price_minor']]],
                        'override' => $this->offlineOverride($this->device, $this->manager->id, $this->cashier->id, 'pos.sale.refund', $id, $when),
                    ]);
                    $cash[$shift]['KES'] -= (int) $line['unit_price_minor'];
                }
            }

            $counted = [['currency' => 'KES', 'amount_minor' => (string) $cash[$shift]['KES']], ['currency' => 'USD', 'amount_minor' => (string) $cash[$shift]['USD']]];
            $this->enqueue('pos.shifts', [...$opening, 'closing' => ['closed_by_id' => $this->cashier->id, 'closed_at' => $dayStart->addHours(11)->toIso8601String(), 'counted' => $counted, 'note' => null]]);
        }

        $this->assertCount(self::DAYS * self::SALES_PER_DAY, $sales);
        $this->assertNotSame($voided['id'], $refunded['id']);

        // Day 7: back online. The range still has room: no new block (NUM-02).
        $this->travelTo($start->addDays(self::DAYS)->addHour());
        $this->ranges('pos.receipt', $seq, $this->device['token'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.next', $seq);

        $first = $this->pushOutbox($this->device);

        // Every record stored; nothing refused or held.
        $this->assertSame([], array_values(array_filter($first['results'], fn ($r) => $r['status'] !== 'stored')));
        $this->assertCount(count($this->outbox), $first['results']);
        $byId = collect($first['results'])->where('kind', '!=', 'pos.shifts')->keyBy('id')->all();
        $this->assertSame('applied', collect($first['results'])->firstWhere('kind', 'pos.voids')['void_status']);
        $this->assertSame('applied', collect($first['results'])->firstWhere('kind', 'pos.refunds')['refund_status']);
        $this->assertSame(['applied', 'applied'], collect($first['results'])->where('kind', 'pos.cash_movements')->pluck('movement_status')->all());
        $this->assertSame('RF-L01-000001', collect($first['results'])->firstWhere('kind', 'pos.refunds')['receipt_number']);

        // Device wins: each sale keeps the till's prices and tax, with what differed flagged.
        foreach ($sales as $id => $sale) {
            $result = $byId[$id];
            $this->assertSame($sale['receipt_number'], $result['receipt_number']);
            $got = collect($result['flags'])->map(fn ($f) => $f['code'].(isset($f['line']) ? ':'.$f['line'] : ''))->sort()->values()->all();
            $this->assertSame($expectedFlags[$id], $got, "flags of sale {$sale['receipt_number']}");
        }
        $this->assertTrue(collect($byId)->contains(fn ($r) => collect($r['flags'] ?? [])->contains('code', 'price_differs')));
        $this->assertTrue(collect($byId)->contains(fn ($r) => collect($r['flags'] ?? [])->contains('code', 'tax_differs')));
        $this->assertTrue(collect($byId)->contains(fn ($r) => collect($r['flags'] ?? [])->contains('code', 'rate_differs')));

        $this->assertStoredOnce($sales, $receipts, $voided, $refunded, $cash);

        // The acknowledgements were lost: the whole outbox goes again. Same answers, nothing new
        // (equal as JSON: a stored flag's detail comes back from jsonb with its keys reordered).
        $again = $this->pushOutbox($this->device);
        $this->assertSame([], array_values(array_filter($again['results'], fn ($r) => $r['status'] !== 'stored')));
        $this->assertEquals(
            collect($first['results'])->where('kind', '!=', 'pos.shifts')->values()->all(),
            collect($again['results'])->where('kind', '!=', 'pos.shifts')->values()->all(),
        );
        $this->assertStoredOnce($sales, $receipts, $voided, $refunded, $cash);

        // Pull from the frozen cursors, in small pages: every change, in cursor order, no gaps.
        $after = $this->pullAllEntities($this->device, $entities, $frozen, 2);
        foreach (['items', 'item_prices'] as $key) {
            $this->assertCursorOrderWithoutGaps($after['pages'][$key], $this->changedSince($key, $key, $frozen[$key]), $key);
        }
        $items = collect($after['pages']['items']);
        $this->assertContains($this->items['ITEM09']->id, $items->pluck('tombstones')->flatten()->all(), 'the archived item arrives as a tombstone');
        $this->assertSame('Item ITEM02 renamed', $items->pluck('upserts')->flatten(1)->firstWhere('id', $this->items['ITEM02']->id)['name']);
        $this->assertNotNull($items->pluck('upserts')->flatten(1)->firstWhere('id', $newItem->id));
        $prices = collect($after['pages']['item_prices']);
        $this->assertSame('60750', $prices->pluck('upserts')->flatten(1)->firstWhere('id', $soapPrice->id)['amount_minor']);
        $this->assertContains($archivedPrice->id, $prices->pluck('tombstones')->flatten()->all(), 'the archived item\'s price arrives as a tombstone');
        $this->assertTrue($after['pages']['exchange_rates'][0]['replace']);
        $this->assertContains('131.00000000', array_column($after['pages']['exchange_rates'][0]['upserts'], 'mid'));
        $this->assertContains('14.0000', array_column(collect($after['pages']['tax_codes'][0]['upserts'])->firstWhere('code', 'VAT_T')['rates'], 'rate'));

        // The device's copy, caught up incrementally, is what a fresh device would get (server wins).
        $fresh = $this->pullAllEntities($this->device, $entities, [], 500);
        foreach ($entities as $key) {
            $this->assertEqualsCanonicalizing(
                array_keys($this->applyPages([], $fresh['pages'][$key])),
                array_keys($this->applyPages($copy[$key], $after['pages'][$key])),
                "{$key} after reconnect",
            );
            $this->assertEquals($this->applyPages([], $fresh['pages'][$key]), $this->applyPages($copy[$key], $after['pages'][$key]), "{$key} rows after reconnect");
        }
    }

    private function setUpWorld(): void
    {
        $this->setUpPos();
        $this->useCoreOverrides();
        $this->cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->inTenant(fn () => LimitRule::create(['role_id' => $this->roles->get('branch_manager')->id, 'key' => 'max_refund_amount', 'value' => '10000']));
        $this->rate('USD', 'KES', '130', at: '-1 day');
        $this->device = $this->pairDevice();

        $this->items['SOAP'] = $this->soap;
        // Prices are multiples of 9 minor units: tax at 12.5 % included is exact.
        foreach (['45000', '27000', '18000', '9000', '36000', '54000', '22500', '31500', '40500'] as $i => $price) {
            $this->item(sprintf('ITEM%02d', $i + 1), $price);
        }
    }

    private function item(string $code, string $price): Item
    {
        return $this->items[$code] = $this->inTenant(function () use ($code, $price) {
            $item = Item::create(['code' => $code, 'name' => "Item {$code}", 'type' => 'stock', 'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id]);
            ItemPrice::create(['price_list_id' => $this->retail->id, 'item_id' => $item->id, 'uom_id' => $this->each->id, 'amount_minor' => $price, 'currency' => 'KES', 'effective_from' => '2026-01-01']);

            return $item;
        });
    }

    /** @return list<array{id: string, code: string, price: string}> the device's sellable priced items, from its own copy */
    private function catalogueFrom(array $copy): array
    {
        $prices = collect($copy['item_prices'])->where('price_list_id', $this->retail->id)->keyBy('item_id');

        return collect($copy['items'])->filter(fn ($item) => $item['sellable'] && $prices->has($item['id']))
            ->map(fn ($item) => ['id' => $item['id'], 'code' => $item['code'], 'price' => $prices[$item['id']]['amount_minor']])
            ->sortBy('code')->values()->all();
    }

    /** One sale as the till makes it offline, from its frozen catalogue, tax rate and USD rate. */
    private function deviceSale(string $shift, int $seq, string $rangeId, CarbonImmutable $at, int $n, array $catalogue, string $taxRate, string $usdRate): array
    {
        $usd = $n % 10 === 5;
        $picks = [[$catalogue[$n % 10], $n % 3 === 0 ? 2 : 1]];
        if (! $usd && $n % 4 === 0) {
            $picks[] = [$catalogue[($n + 3) % 10], 1];
        }

        $lines = array_map(function (array $pick) use ($taxRate) {
            [$item, $qty] = $pick;
            $total = BigDecimal::of($item['price'])->multipliedBy($qty);
            $tax = $total->multipliedBy($taxRate)->dividedBy(BigDecimal::of('100')->plus($taxRate), 0, RoundingMode::HalfUp);

            return $this->line([
                'item_id' => $item['id'], 'item_name' => $item['code'], 'qty' => (string) $qty, 'unit_price_minor' => $item['price'], 'list_price_minor' => $item['price'],
                'tax_rate' => $taxRate, 'tax_minor' => (string) $tax, 'total_minor' => (string) $total,
            ]);
        }, $picks);

        $sale = $this->saleBody($shift, $seq, [
            'cashier_id' => $this->cashier->id, 'actor_proof' => null, 'number_range_id' => $rangeId,
            'sold_at' => $at->toIso8601String(), 'offline' => true, 'lines' => $lines,
        ]);
        $total = $sale['totals']['total_minor'];

        if ($usd) {
            $inKes = BigDecimal::of('1000')->multipliedBy($usdRate)->toScale(0, RoundingMode::Unnecessary);
            $sale['payments'] = [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_usd']->id, 'currency' => 'USD', 'amount_minor' => '1000', 'amount_in_sale_minor' => (string) $inKes,
                'rate' => ['rate' => $usdRate, 'base' => 'USD', 'quote' => 'KES', 'kind' => 'shop'], 'status' => 'confirmed']];
            $sale['change'] = ['currency' => 'KES', 'amount_minor' => (string) $inKes->minus($total), 'rate' => null];
        } elseif ($n % 10 === 7) {
            $sale['payments'][0]['payment_method_id'] = $this->methods['mpesa']->id;
            $sale['payments'][0]['provider_reference'] = sprintf('QK%08d', $n);
        }

        return $sale;
    }

    /** Cash in the drawer from a sale (+1) or its void (-1), as the till counts it. */
    private function countCash(array &$drawer, array $sale, int $sign): void
    {
        $payment = $sale['payments'][0];

        if ($payment['payment_method_id'] === $this->methods['mpesa']->id) {
            return;
        }

        $drawer[$payment['currency']] += $sign * (int) $payment['amount_minor'];
        $drawer[$sale['change']['currency']] -= $sign * (int) $sale['change']['amount_minor'];
    }

    /**
     * What the server notices and records without refusing (device wins):
     * the cashier's sign-in can't be attested yet (AUTH-07: core has no
     * attestations, every sale is `actor_unverified`); soap's price rose on
     * day 3 (price_differs); the tax rate rose on day 4 (tax_differs, every
     * line); the USD rate changed on day 5 (rate_differs); the archived item
     * has no server price any more (price_unknown, also for sales made
     * before it was archived: the server resolves prices at upload time).
     *
     * @return list<string> sorted "code" or "code:line"
     */
    private function flagsExpected(array $sale, string $date, CarbonImmutable $at, CarbonImmutable $start): array
    {
        $flags = ['actor_unverified'];

        foreach ($sale['lines'] as $index => $line) {
            $no = $index + 1;
            if ($line['item_id'] === $this->soap->id && $date >= '2026-11-05') {
                $flags[] = "price_differs:{$no}";
            }
            if ($line['item_id'] === $this->items['ITEM09']->id) {
                $flags[] = "price_unknown:{$no}";
            }
            if ($date >= '2026-11-06') {
                $flags[] = "tax_differs:{$no}";
            }
        }

        if ($sale['payments'][0]['currency'] === 'USD' && $at->greaterThanOrEqualTo($start->addDays(5)->addHours(-2))) {
            $flags[] = 'rate_differs';
        }

        sort($flags);

        return $flags;
    }

    /** NFR-04, NUM-02, POS-04: each record once, numbers unique and from the range, shifts balanced. */
    private function assertStoredOnce(array $sales, array $receipts, array $voided, array $refunded, array $cash): void
    {
        $this->inTenant(function () use ($sales, $receipts, $voided, $refunded, $cash) {
            $this->assertSame(count($sales), Sale::count());
            $this->assertSame(count($sales), AuditEntry::where('action', 'pos.sale.create')->count());
            $this->assertEqualsCanonicalizing(array_keys($sales), Sale::pluck('id')->all());
            $this->assertSame(1, SaleVoid::count());
            $this->assertSame(1, Refund::count());
            $this->assertSame(2, CashMovement::count());
            $this->assertSame(Sale::VOIDED, Sale::findOrFail($voided['id'])->status);
            $this->assertSame('1.000000', SaleLine::findOrFail($refunded['lines'][0]['id'])->refunded_qty);

            // The till's prices and tax as sold, line by line.
            $deviceLines = collect($sales)->pluck('lines')->flatten(1)->keyBy('id');
            $this->assertSame($deviceLines->count(), SaleLine::count());
            foreach (SaleLine::get(['id', 'unit_price_minor', 'tax_minor', 'total_minor']) as $line) {
                $sent = $deviceLines[$line->id];
                $this->assertSame([$sent['unit_price_minor'], $sent['tax_minor'], $sent['total_minor']], [(string) $line->unit_price_minor, (string) $line->tax_minor, (string) $line->total_minor]);
            }

            // Receipt numbers: unique, consecutive from the range's start, inside it.
            $seqs = Sale::orderBy('receipt_seq')->pluck('receipt_seq')->all();
            $this->assertSame(range($receipts['from'], $receipts['from'] + count($sales) - 1), $seqs);
            $this->assertSame(count($sales), Sale::distinct()->count('receipt_number'));
            $this->assertSame([$receipts['id']], Sale::distinct()->pluck('number_range_id')->all());
            $range = NumberRange::findOrFail($receipts['id']);
            $this->assertSame([NumberRange::ACTIVE, $receipts['from'] + count($sales)], [$range->status, $range->next_value]);
            $this->assertLessThanOrEqual($range->range_to, max($seqs));

            // Seven shifts, each closed, expected cash as the till counted it.
            $this->assertSame(self::DAYS, Shift::where('status', Shift::CLOSED)->count());
            $this->assertSame(0, Shift::where('status', '!=', Shift::CLOSED)->count());
            foreach ($cash as $shiftId => $drawer) {
                foreach ($drawer as $currency => $amount) {
                    $balance = ShiftBalance::where('shift_id', $shiftId)->where('currency', $currency)->sole();
                    $this->assertSame([(string) $amount, (string) $amount, '0'], [(string) $balance->expected_minor, (string) $balance->counted_minor, (string) $balance->variance_minor], "shift {$shiftId} {$currency}");
                }
            }
            $this->assertSame(self::DAYS, AuditEntry::where('action', 'pos.shift.close')->count());
        });
    }

    /**
     * Pages arrive in cursor order with no gaps: page k holds exactly the
     * next ids of the server's change log after the frozen cursor.
     *
     * @param  array<string, array{0: int, 1: int}>  $expected
     */
    private function assertCursorOrderWithoutGaps(array $pages, array $expected, string $key): void
    {
        $order = array_keys($expected);
        $offset = 0;

        foreach ($pages as $index => $page) {
            $ids = [...array_column($page['upserts'], 'id'), ...$page['tombstones']];
            $this->assertEqualsCanonicalizing(array_slice($order, $offset, count($ids)), $ids, "{$key} page {$index}");
            $offset += count($ids);
        }

        $this->assertSame(count($order), $offset, "{$key}: every change delivered");
        $this->assertGreaterThan(1, count($pages), "{$key}: paged");
    }
}
