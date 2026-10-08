<?php

namespace App\Core\MasterData\Prices\Http\Requests;

use Closure;

/**
 * One price's input (MD-03 follow-up):
 * `{item_id, uom_id, amount_minor: "12450", currency: "KES", effective_from?: "2026-11-01", min_quantity?: "1"}`.
 *
 * - `amount_minor` is a string of digits in minor units of the list's
 *   currency (ADR 003: never a float); zero is allowed (a free item).
 * - `currency` must be the list's (checked by PriceWriter): the client
 *   says which currency it meant.
 * - `effective_from` defaults to today in the company's time zone.
 * - `min_quantity` (a quantity break, in the price's unit) is a decimal
 *   string above zero with up to 6 decimals; 1 by default.
 */
final class PriceRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(string $prefix = ''): array
    {
        return [
            "{$prefix}item_id" => ['required', 'string', 'uuid'],
            "{$prefix}uom_id" => ['required', 'string', 'uuid'],
            "{$prefix}amount_minor" => ['required', 'string', 'regex:/^\d{1,18}\z/'],
            "{$prefix}currency" => ['required', 'string', 'regex:/^[A-Z]{3}\z/'],
            "{$prefix}effective_from" => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            "{$prefix}min_quantity" => ['sometimes', 'nullable', self::quantity()],
        ];
    }

    /** A decimal string (or whole number) above zero: up to 12 digits and 6 decimals. Never a float. */
    private static function quantity(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $text = is_int($value) ? (string) $value : $value;

            if (! is_string($text) || preg_match('/^\d{1,12}(\.\d{1,6})?\z/', $text) !== 1 || preg_match('/[1-9]/', $text) !== 1) {
                $fail(__('core.price.min_quantity_invalid'));
            }
        };
    }

    /** @return array<string, string> */
    public static function messages(string $prefix = ''): array
    {
        return [
            "{$prefix}amount_minor.regex" => __('core.price.amount_invalid'),
            "{$prefix}amount_minor.string" => __('core.price.amount_invalid'),
        ];
    }

    /** @return array<string, string> */
    public static function attributes(string $prefix = ''): array
    {
        return [
            "{$prefix}item_id" => __('core.price.attributes.item'),
            "{$prefix}uom_id" => __('core.price.attributes.unit'),
            "{$prefix}amount_minor" => __('core.price.attributes.amount'),
            "{$prefix}currency" => __('core.price.attributes.currency'),
            "{$prefix}effective_from" => __('core.price.attributes.effective_from'),
            "{$prefix}min_quantity" => __('core.price.attributes.min_quantity'),
        ];
    }
}
