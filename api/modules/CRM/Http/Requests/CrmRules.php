<?php

namespace Modules\CRM\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Validation shared by CRM's Form Requests. Ids are checked to exist
 * under row-level security (another tenant's do not); whether they belong
 * to the record's company is checked by the services. Money is minor
 * units as digit strings, quantities and percentages decimal strings
 * (ADR 003).
 */
final class CrmRules
{
    public const MINOR = 'regex:/^\d{1,18}$/';

    public const QTY = 'regex:/^\d{1,14}(\.\d{1,6})?$/';

    public const PERCENT = 'regex:/^(100(\.0{1,2})?|\d{1,2}(\.\d{1,2})?)$/';

    /** @return list<mixed> */
    public static function id(string $table, bool $required = false): array
    {
        return [$required ? 'required' : 'sometimes', ...($required ? [] : ['nullable']), 'uuid', Rule::exists($table, 'id')];
    }
}
