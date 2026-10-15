<?php

namespace Modules\FixedAssets\Depreciation;

/** FA-07 to FA-10: one span's charge in base minor units, and the units it counted (days, half months or meter units). */
final class Charge
{
    public function __construct(
        public readonly int $amount,
        public readonly ?string $units = null,
    ) {}
}
