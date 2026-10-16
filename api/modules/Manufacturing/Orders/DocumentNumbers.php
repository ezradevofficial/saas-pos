<?php

namespace Modules\Manufacturing\Orders;

use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;

/**
 * MFG-25, NUM-01: numbers of production orders, material issues and
 * returns and production reports, from the format in force at the
 * document's branch (else company, else tenant; the defaults below, reset
 * yearly), drawn inside the document's own transaction.
 */
class DocumentNumbers
{
    public const ORDER = 'manufacturing.order';

    public const ISSUE = 'manufacturing.issue';

    public const RETURN = 'manufacturing.return';

    public const REPORT = 'manufacturing.report';

    public const TYPES = [
        self::ORDER => 'MO-{BRANCH}-{YYYY}-{00001}',
        self::ISSUE => 'MI-{BRANCH}-{YYYY}-{00001}',
        self::RETURN => 'MR-{BRANCH}-{YYYY}-{00001}',
        self::REPORT => 'PR-{BRANCH}-{YYYY}-{00001}',
    ];

    public function __construct(private readonly Numbering $numbering) {}

    /** @return list<DocumentNumberType> */
    public static function types(): array
    {
        return array_map(
            fn (string $key, string $pattern) => new DocumentNumberType($key, 'manufacturing', $pattern, NumberFormat::RESET_YEARLY, ['BRANCH', 'LOCATION'], langKey: 'manufacturing.numbering.'.substr($key, strlen('manufacturing.'))),
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
