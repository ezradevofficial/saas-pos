<?php

namespace Modules\FuelStation\Support;

use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Modules\FuelStation\FuelStationServiceProvider;

/** FUEL-35, NUM-01: settlement, reconciliation and delivery numbers, per branch and year. */
class Numbers
{
    public const SETTLEMENT = 'fuel.settlement';

    public const RECONCILIATION = 'fuel.reconciliation';

    public const DELIVERY = 'fuel.delivery';

    private const PATTERNS = [
        self::SETTLEMENT => 'FS-{BRANCH}-{YYYY}-{00001}',
        self::RECONCILIATION => 'FR-{BRANCH}-{YYYY}-{00001}',
        self::DELIVERY => 'FD-{BRANCH}-{YYYY}-{00001}',
    ];

    public function __construct(private readonly Numbering $numbering) {}

    /** @return list<DocumentNumberType> */
    public static function types(): array
    {
        return array_map(
            fn (string $key) => new DocumentNumberType($key, FuelStationServiceProvider::MODULE, self::PATTERNS[$key], NumberFormat::RESET_YEARLY, ['BRANCH', 'LOCATION'], langKey: 'fuel.numbering.'.substr($key, 5)),
            array_keys(self::PATTERNS),
        );
    }

    public function next(string $type, string $locationId): string
    {
        $location = Location::query()->findOrFail($locationId);
        $branch = Branch::query()->findOrFail($location->branch_id);

        return $this->numbering->next($type, new NumberContext(Company::query()->findOrFail($branch->company_id), $branch, $location))->number;
    }
}
