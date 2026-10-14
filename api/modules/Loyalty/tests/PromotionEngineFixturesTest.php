<?php

namespace Modules\Loyalty\Tests;

use Modules\Loyalty\Promotions\PromotionEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * LOY-10: the shared promotion and points cases
 * (tests/Fixtures/loyalty/promotion-cases.json) on the server; the till's
 * Jest test (pos/src/loyalty/engine.test.js) runs the same file.
 */
class PromotionEngineFixturesTest extends TestCase
{
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 3).'/tests/Fixtures/loyalty/promotion-cases.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{0: array, 1: array}> */
    public static function cases(): iterable
    {
        $fixture = self::fixture();

        foreach ($fixture['cases'] as $case) {
            $context = array_replace_recursive($fixture['context'], $case['context'] ?? []);
            // A list in a case replaces the default list (array_replace_recursive would merge it).
            foreach (['customer_tags', 'coupons'] as $key) {
                if (isset($case['context'][$key])) {
                    $context[$key] = $case['context'][$key];
                }
            }

            yield $case['name'] => [$case, $context];
        }
    }

    #[DataProvider('cases')]
    public function test_each_shared_case(array $case, array $context): void
    {
        $this->assertSame($case['expected'], PromotionEngine::evaluate($case['lines'], $context, $case['promotions']));
    }

    public function test_the_fixture_has_cases_of_every_type(): void
    {
        $types = collect(self::fixture()['cases'])->flatMap(fn (array $case) => array_column($case['promotions'], 'type'))->unique()->sort()->values()->all();

        $this->assertSame(['amount_off', 'bundle', 'buy_x_get_y', 'percent'], $types);
    }

    public function test_points_earn_reverse_and_redeem(): void
    {
        $points = self::fixture()['points'];

        foreach ($points['earn'] as $case) {
            $this->assertSame($case['expected'], PromotionEngine::pointsEarned($case['eligible_minor'], $case['earn_points'], $case['earn_spend_minor']), $case['name']);
        }

        foreach ($points['reverse'] as $case) {
            $this->assertSame($case['expected'], PromotionEngine::pointsToReverse($case['earned'], $case['sale_total_minor'], $case['refunded_minor'], $case['already_reversed']), $case['name']);
        }

        foreach ($points['redeem'] as $case) {
            $this->assertSame($case['expected'], PromotionEngine::redeemValue($case['points'], $case['point_value_minor']), $case['name']);
        }
    }

    public function test_the_result_does_not_depend_on_the_order_promotions_arrive_in(): void
    {
        foreach (self::cases() as [$case, $context]) {
            $this->assertSame($case['expected'], PromotionEngine::evaluate($case['lines'], $context, array_reverse($case['promotions'])), $case['name']);
        }
    }
}
