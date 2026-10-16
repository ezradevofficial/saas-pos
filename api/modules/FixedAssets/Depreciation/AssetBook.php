<?php

namespace Modules\FixedAssets\Depreciation;

use Carbon\CarbonImmutable;
use Modules\FixedAssets\Models\Asset;

/**
 * FA-07 to FA-11: what a depreciation charge is worked out from: the
 * asset's terms (method, life, residual value, declining rate, total
 * units) and its book (cost, accumulated depreciation, impairment), all
 * base minor units as integers. Immutable; after() gives the book once a
 * charge is added.
 */
final class AssetBook
{
    public const STRAIGHT_LINE = 'straight_line';

    public const DECLINING_BALANCE = 'declining_balance';

    public const UNITS_OF_PRODUCTION = 'units_of_production';

    public const NONE = 'none';

    public const METHODS = [self::STRAIGHT_LINE, self::DECLINING_BALANCE, self::UNITS_OF_PRODUCTION, self::NONE];

    public function __construct(
        public readonly string $method,
        public readonly CarbonImmutable $inService,
        public readonly ?int $lifeMonths,
        public readonly int $cost,
        public readonly int $accumulated = 0,
        public readonly int $impairment = 0,
        public readonly int $residual = 0,
        public readonly ?string $rate = null,
        public readonly ?string $totalUnits = null,
    ) {}

    public static function of(Asset $asset): self
    {
        return new self(
            method: (string) ($asset->method ?? self::NONE),
            inService: CarbonImmutable::parse($asset->in_service_on ?? $asset->acquired_on)->startOfDay(),
            lifeMonths: $asset->useful_life_months === null ? null : (int) $asset->useful_life_months,
            cost: (int) $asset->book_cost_minor,
            accumulated: (int) $asset->accumulated_minor,
            impairment: (int) $asset->impairment_minor,
            residual: (int) $asset->residual_minor,
            rate: $asset->declining_rate === null ? null : (string) $asset->declining_rate,
            totalUnits: $asset->total_units === null ? null : (string) $asset->total_units,
        );
    }

    /** The net book value: cost less accumulated depreciation and impairment. */
    public function bookValue(): int
    {
        return $this->cost - $this->accumulated - $this->impairment;
    }

    /** What is left to depreciate: the net book value above the residual value, never below zero. */
    public function depreciable(): int
    {
        return max(0, $this->bookValue() - $this->residual);
    }

    /** The same asset with $charge more accumulated depreciation. */
    public function after(int $charge): self
    {
        return new self($this->method, $this->inService, $this->lifeMonths, $this->cost, $this->accumulated + $charge,
            $this->impairment, $this->residual, $this->rate, $this->totalUnits);
    }

    /** The last day of the life (days convention), or null without a life. */
    public function lifeEnd(): ?CarbonImmutable
    {
        return $this->lifeMonths === null ? null : $this->inService->addMonthsNoOverflow($this->lifeMonths)->subDay();
    }
}
