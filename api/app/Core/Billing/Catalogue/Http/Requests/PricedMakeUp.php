<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

use App\Core\Billing\Catalogue\Models\CataloguePrice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Validator;

/**
 * SUB-01: what a module or bundle is made of (its modules, add-ons,
 * outlets, includes, quoted flag) is fixed while it has a live price, so a
 * price always means the same thing. A change is a new item with its own
 * price. A database trigger enforces the same rule.
 */
final class PricedMakeUp
{
    /** @param  list<string>  $fields */
    public static function check(Validator $validator, array $input, ?Model $item, string $type, array $fields): void
    {
        if ($item === null) {
            return;
        }

        $priced = CataloguePrice::query()->where('item_type', $type)->where('item_key', $item->getAttribute('key'))->whereNull('archived_at')->exists();

        if (! $priced) {
            return;
        }

        foreach ($fields as $field) {
            if (array_key_exists($field, $input) && ! self::same($input[$field], $item->getAttribute($field))) {
                $validator->errors()->add($field, __('billing.catalogue.makeup_fixed'));
            }
        }
    }

    private static function same(mixed $given, mixed $stored): bool
    {
        if (is_bool($stored)) {
            return filter_var($given, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === $stored;
        }

        if (is_array($stored) || is_array($given)) {
            $norm = function (mixed $value): array {
                $value = (array) $value;
                ksort($value);

                return array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $value);
            };

            return $norm($given) === $norm($stored);
        }

        return (string) $given === (string) $stored;
    }
}
