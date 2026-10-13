<?php

namespace Modules\Inventory\Documents;

use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Modules\Inventory\InventoryServiceProvider;

/**
 * INV-23, NUM-01: numbers of transfers, adjustments and counts, from the
 * format in force at the document's branch (else company, else tenant;
 * the defaults below, reset yearly), drawn inside the document's own
 * transaction.
 */
class DocumentNumbers
{
    public const TYPES = [
        TransferType::KEY => 'TR-{BRANCH}-{YYYY}-{00001}',
        AdjustmentType::KEY => 'ADJ-{BRANCH}-{YYYY}-{00001}',
        CountType::KEY => 'CNT-{BRANCH}-{YYYY}-{00001}',
    ];

    public function __construct(private readonly Numbering $numbering) {}

    /** @return list<DocumentNumberType> */
    public static function types(): array
    {
        return array_map(
            fn (string $key, string $pattern) => new DocumentNumberType($key, InventoryServiceProvider::MODULE, $pattern, NumberFormat::RESET_YEARLY, ['BRANCH', 'LOCATION'], langKey: 'inventory.numbering.'.substr($key, strlen('inventory.'))),
            array_keys(self::TYPES),
            self::TYPES,
        );
    }

    public function next(string $type, string $companyId, string $branchId, string $locationId): string
    {
        return $this->numbering->next($type, new NumberContext(
            Company::query()->findOrFail($companyId),
            Branch::query()->findOrFail($branchId),
            Location::query()->findOrFail($locationId),
        ))->number;
    }
}
