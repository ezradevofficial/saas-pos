<?php

namespace Modules\Stores\Documents;

use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;

/**
 * STR-15, NUM-01: numbers of requisitions, issues and returns, from the
 * format in force at the document's branch (else company, else tenant;
 * the defaults below, reset yearly), drawn inside the document's own
 * transaction.
 */
class DocumentNumbers
{
    public const ISSUE = 'stores.issue';

    public const RETURN = 'stores.return';

    public const TYPES = [
        RequisitionType::KEY => 'REQ-{BRANCH}-{YYYY}-{00001}',
        self::ISSUE => 'ISS-{BRANCH}-{YYYY}-{00001}',
        self::RETURN => 'SRN-{BRANCH}-{YYYY}-{00001}',
    ];

    public function __construct(private readonly Numbering $numbering) {}

    /** @return list<DocumentNumberType> */
    public static function types(): array
    {
        return array_map(
            fn (string $key, string $pattern) => new DocumentNumberType($key, 'stores', $pattern, NumberFormat::RESET_YEARLY, ['BRANCH', 'LOCATION'], langKey: 'stores.numbering.'.substr($key, strlen('stores.'))),
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
