<?php

namespace Modules\FixedAssets\Support;

use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use DateTimeInterface;

/**
 * FA-27, NUM-01: asset tags (never reset) and the numbers of transfers,
 * disposals and work orders (yearly), from the format in force at the
 * branch (else company, else tenant), drawn inside the record's own
 * transaction.
 */
class Numbers
{
    public const ASSET = 'fixed_assets.asset';

    public const TRANSFER = 'fixed_assets.transfer';

    public const DISPOSAL = 'fixed_assets.disposal';

    public const WORK_ORDER = 'fixed_assets.work_order';

    public const TYPES = [
        self::ASSET => ['FA-{BRANCH}-{00001}', NumberFormat::RESET_NEVER],
        self::TRANSFER => ['FAT-{BRANCH}-{YYYY}-{00001}', NumberFormat::RESET_YEARLY],
        self::DISPOSAL => ['FAD-{BRANCH}-{YYYY}-{00001}', NumberFormat::RESET_YEARLY],
        self::WORK_ORDER => ['WO-{BRANCH}-{YYYY}-{00001}', NumberFormat::RESET_YEARLY],
    ];

    public function __construct(private readonly Numbering $numbering) {}

    /** @return list<DocumentNumberType> */
    public static function types(): array
    {
        return array_map(
            fn (string $key, array $format) => new DocumentNumberType($key, 'fixed_assets', $format[0], $format[1], ['BRANCH', 'LOCATION'],
                langKey: 'fixed_assets.numbering.'.substr($key, strlen('fixed_assets.'))),
            array_keys(self::TYPES),
            self::TYPES,
        );
    }

    public function next(string $type, string $companyId, string $branchId, ?string $locationId = null, ?DateTimeInterface $at = null): string
    {
        return $this->numbering->next($type, new NumberContext(
            Company::query()->findOrFail($companyId),
            Branch::query()->findOrFail($branchId),
            $locationId === null ? null : Location::query()->findOrFail($locationId),
            null,
            $at,
        ))->number;
    }
}
