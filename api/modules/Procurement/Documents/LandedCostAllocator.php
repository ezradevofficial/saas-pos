<?php

namespace Modules\Procurement\Documents;

use App\Core\Currency\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * PRC-17: split a total in integer minor units over parts in proportion to
 * their basis (value, quantity or weight), by largest remainder
 * (Money::allocate): the parts always add up to the total exactly, a part
 * with a zero basis gets nothing, ties go to the earlier part. Bases are
 * exact decimals (up to 6 places), turned into whole-number ratios by
 * scaling them all by 10^6.
 */
final class LandedCostAllocator
{
    private const SCALE = 6;

    /**
     * @param  array<string, string>  $bases  part key => basis (decimal string, ≥ 0)
     * @return array<string, int> part key => share, summing to $totalMinor
     */
    public static function allocate(int $totalMinor, array $bases, string $currency = 'XXX'): array
    {
        if ($bases === []) {
            throw new InvalidArgumentException('Allocate over at least one part.');
        }

        $ratios = [];

        foreach ($bases as $key => $basis) {
            $scaled = BigDecimal::of($basis)->toScale(self::SCALE, RoundingMode::HalfUp)->withPointMovedRight(self::SCALE)->toBigInteger();

            if ($scaled->isNegative()) {
                throw new InvalidArgumentException("The basis of [{$key}] is negative.");
            }

            $ratios[] = (string) $scaled;
        }

        $shares = Money::ofMinor($totalMinor, $currency)->allocate($ratios);
        $result = [];

        foreach (array_keys($bases) as $index => $key) {
            $result[$key] = (int) $shares[$index]->minor();
        }

        return $result;
    }
}
