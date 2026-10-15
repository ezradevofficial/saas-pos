<?php

namespace Modules\Payroll\Http\Resources;

use Illuminate\Database\Eloquent\Model;

/** Small shapes Payroll's resources share: money and references. */
final class Refs
{
    /** `{amount_minor, currency}` (the amount as a digit string, ADR 003). */
    public static function money(int|string|null $minor, ?string $currency): ?array
    {
        return $minor === null || $currency === null ? null : ['amount_minor' => (string) $minor, 'currency' => $currency];
    }

    public static function named(?Model $model, string $label = 'name'): ?array
    {
        return $model === null ? null : ['id' => $model->getKey(), $label => $model->{$label}, ...(isset($model->code) ? ['code' => $model->code] : [])];
    }

    /** Each amount field of $rows as money in $currency. */
    public static function rows(array $rows, string $currency, array $fields): array
    {
        return array_map(function (array $row) use ($currency, $fields) {
            foreach ($fields as $field) {
                if (array_key_exists($field, $row)) {
                    $key = str_ends_with($field, '_minor') ? substr($field, 0, -6) : $field;
                    $row[$key] = self::money($row[$field], $currency);
                    unset($row[$field]);
                }
            }

            return $row;
        }, $rows);
    }
}
