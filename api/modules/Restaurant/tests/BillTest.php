<?php

namespace Modules\Restaurant\Tests;

use Brick\Math\BigInteger;
use InvalidArgumentException;
use Modules\Restaurant\Billing\Bill;
use PHPUnit\Framework\TestCase;

/**
 * REST-13, REST-14: the bill's maths. The same fixture is replayed by the
 * till (`pos/src/restaurant/bill.test.js`), so both sides agree.
 */
class BillTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../../tests/Fixtures/restaurant/bill.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_shared_fixture_is_replayed_exactly(): void
    {
        foreach ($this->fixture()['cases'] as $case) {
            $actual = $case['kind'] === 'totals'
                ? Bill::totals($case['lines'], $case['service'], $case['tip_minor'])
                : Bill::split($case['lines'], $case['mode'], $case['service'], $case['tip_minor'], $case['options']);

            $this->assertSame($case['expect'], $actual, $case['name']);
        }

        foreach ($this->fixture()['equal_qty'] as $row) {
            $this->assertSame($row['expect'], Bill::equalQty($row['qty'], $row['index'], $row['count']));
        }
    }

    public function test_every_split_adds_up_to_the_order_total_to_the_unit(): void
    {
        foreach ($this->fixture()['cases'] as $case) {
            if ($case['kind'] !== 'split') {
                continue;
            }

            $whole = Bill::totals($case['lines'], $case['service'], $case['tip_minor']);

            foreach (['lines_minor', 'service_charge_minor', 'service_tax_minor', 'tip_minor', 'total_minor'] as $key) {
                $sum = array_reduce($case['expect'], fn (BigInteger $c, array $p) => $c->plus($p[$key]), BigInteger::zero());
                $this->assertSame($whole[$key], (string) $sum, "{$case['name']}: {$key}");
            }
        }
    }

    public function test_service_charge_is_on_the_net_and_taxed_by_the_service_item(): void
    {
        // One exclusive line of 1,000.00 at 16 %: the charge is 10 % of the net 1,000.00, not of 1,160.00.
        $bill = Bill::totals([['id' => 'a', 'qty' => '1', 'unit_price_minor' => '100000', 'tax_rate' => '16', 'tax_inclusive' => false]], ['percent' => '10', 'tax_rate' => '16']);
        $this->assertSame('10000', $bill['service_charge_minor']);
        $this->assertSame('1600', $bill['service_tax_minor']);
        $this->assertSame('127600', $bill['total_minor']);

        // A tax-free service item adds no tax; a tax-inclusive line is stripped of its tax before the percentage.
        $bill = Bill::totals([['id' => 'a', 'qty' => '1', 'unit_price_minor' => '116000', 'tax_rate' => '16', 'tax_inclusive' => true]], ['percent' => '10']);
        $this->assertSame('10000', $bill['service_charge_minor']);
        $this->assertSame('0', $bill['service_tax_minor']);
        $this->assertSame('126000', $bill['total_minor']);
    }

    public function test_modifiers_change_the_price_and_void_lines_count_for_nothing(): void
    {
        $bill = Bill::totals([
            ['id' => 'a', 'qty' => '2', 'unit_price_minor' => '25000', 'modifiers' => [['price_minor' => '3000'], ['price_minor' => '0']], 'tax_inclusive' => false],
            ['id' => 'b', 'qty' => '1', 'unit_price_minor' => '99999', 'tax_inclusive' => false, 'void' => true],
        ]);
        $this->assertSame('56000', $bill['total_minor']);
        $this->assertCount(1, $bill['lines']);
    }

    public function test_a_bad_split_is_refused(): void
    {
        $lines = [['id' => 'a', 'qty' => '1', 'unit_price_minor' => '1000', 'tax_inclusive' => false], ['id' => 'b', 'qty' => '1', 'unit_price_minor' => '1000', 'tax_inclusive' => false]];

        foreach ([
            ['equal', ['parts' => 1]],
            ['item', ['groups' => [['a'], ['a']]]],
            ['item', ['groups' => [['a'], ['zz']]]],
            ['item', ['groups' => [['a']]]],
            ['seat', []],
            ['nonsense', []],
        ] as [$mode, $options]) {
            try {
                Bill::split($lines, $mode, [], 0, $options);
                $this->fail("{$mode} should be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
