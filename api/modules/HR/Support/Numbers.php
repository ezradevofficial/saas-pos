<?php

namespace Modules\HR\Support;

use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;

/**
 * HR-36, NUM-01: numbers of employees (never reset), leave requests and
 * disciplinary cases (yearly), from the format in force at the record's
 * branch (else company, else tenant), drawn inside the record's own
 * transaction.
 */
class Numbers
{
    public const EMPLOYEE = 'hr.employee';

    public const LEAVE_REQUEST = 'hr.leave_request';

    public const DISCIPLINARY_CASE = 'hr.disciplinary_case';

    public const TYPES = [
        self::EMPLOYEE => ['EMP-{00001}', NumberFormat::RESET_NEVER],
        self::LEAVE_REQUEST => ['LV-{YYYY}-{00001}', NumberFormat::RESET_YEARLY],
        self::DISCIPLINARY_CASE => ['DC-{YYYY}-{00001}', NumberFormat::RESET_YEARLY],
    ];

    public function __construct(private readonly Numbering $numbering) {}

    /** @return list<DocumentNumberType> */
    public static function types(): array
    {
        return array_map(
            fn (string $key, array $format) => new DocumentNumberType($key, 'hr', $format[0], $format[1], ['BRANCH'], langKey: 'hr.numbering.'.substr($key, strlen('hr.'))),
            array_keys(self::TYPES),
            self::TYPES,
        );
    }

    public function next(string $type, string $companyId, ?string $branchId = null): string
    {
        return $this->numbering->next($type, new NumberContext(
            Company::query()->findOrFail($companyId),
            $branchId === null ? null : Branch::query()->findOrFail($branchId),
        ))->number;
    }
}
