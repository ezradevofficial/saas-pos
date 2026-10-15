<?php

namespace Modules\Payroll\Support;

use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Company;

/** NUM-01, PAY-14: run numbers `PR-{YYYY}-{00001}` (yearly, per company), drawn inside the run's transaction. */
class Numbers
{
    public const RUN = 'payroll.run';

    public function __construct(private readonly Numbering $numbering) {}

    /** @return list<DocumentNumberType> */
    public static function types(): array
    {
        return [new DocumentNumberType(self::RUN, 'payroll', 'PR-{YYYY}-{00001}', NumberFormat::RESET_YEARLY, [], langKey: 'payroll.numbering.run')];
    }

    public function next(string $companyId): string
    {
        return $this->numbering->next(self::RUN, new NumberContext(Company::query()->findOrFail($companyId), null))->number;
    }
}
