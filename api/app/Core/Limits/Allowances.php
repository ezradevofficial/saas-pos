<?php

namespace App\Core\Limits;

use InvalidArgumentException;

/**
 * LIM-01: how many of each unit (Units) the tenant's plan allows; null is
 * unlimited, and a unit not named is unlimited. `plan` is what the plan
 * sells, to price extra units through the catalogue (null: no price shown).
 */
final class Allowances
{
    /** @var array<string, ?int> */
    public readonly array $limits;

    /** @param  array<string, ?int>  $limits */
    public function __construct(array $limits = [], public readonly ?PlanSelection $plan = null)
    {
        foreach ($limits as $unit => $allowed) {
            if (! in_array($unit, Units::ALL, true) || ($allowed !== null && $allowed < 0)) {
                throw new InvalidArgumentException("Invalid allowance [{$unit}].");
            }
        }

        $this->limits = $limits;
    }

    public static function unlimited(): self
    {
        return new self;
    }

    /** The allowed count of $unit, or null when unlimited. */
    public function of(string $unit): ?int
    {
        return $this->limits[$unit] ?? null;
    }
}
