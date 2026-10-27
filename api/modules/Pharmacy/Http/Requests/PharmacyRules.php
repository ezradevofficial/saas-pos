<?php

namespace Modules\Pharmacy\Http\Requests;

use Illuminate\Validation\Rule;

/** Validation pieces shared by Pharmacy's Form Requests. */
final class PharmacyRules
{
    /** A decimal string with up to 14 digits and 6 decimals (quantities are exact, PHA-05). */
    public const QTY = 'regex:/^\d{1,14}(\.\d{1,6})?$/';

    /** @return list<mixed> */
    public static function qty(): array
    {
        return ['required', 'string', self::QTY, 'not_regex:/^0+(\.0+)?$/'];
    }

    /** @return list<mixed> */
    public static function location(): array
    {
        return ['required', 'uuid', Rule::exists('locations', 'id')->whereNull('archived_at')];
    }

    /** @return list<mixed> */
    public static function item(): array
    {
        return ['required', 'uuid', Rule::exists('items', 'id')->whereNull('archived_at')];
    }

    /** @return list<mixed> */
    public static function lot(): array
    {
        return ['required', 'uuid', Rule::exists('pharmacy_lots', 'id')];
    }
}
