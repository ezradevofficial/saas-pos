<?php

namespace App\Core\Currency\Http\Requests;

/** Shared tenant currency validation (CUR-01). */
final class TenantCurrencyRules
{
    public const MAX_DECIMALS = 4;

    public const MAX_CASH_ROUNDING = 1_000_000_000;

    /** @return array<string, list<mixed>> */
    public static function settings(): array
    {
        return [
            'decimals' => ['sometimes', 'required', 'integer', 'between:0,'.self::MAX_DECIMALS],
            'cash_rounding_minor' => ['sometimes', 'required', 'integer', 'between:1,'.self::MAX_CASH_ROUNDING],
        ];
    }

    /** @return array<string, string> */
    public static function attributes(): array
    {
        return [
            'code' => __('core.currency.attributes.code'),
            'decimals' => __('core.currency.attributes.decimals'),
            'cash_rounding_minor' => __('core.currency.attributes.cash_rounding_minor'),
            'active' => __('core.currency.attributes.active'),
        ];
    }
}
