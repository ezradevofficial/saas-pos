<?php

namespace Modules\POS\Payments;

use Illuminate\Database\Eloquent\Model;

/**
 * POS-09: adds a flag (what the server noticed, for review) to a stored
 * sale or refund after the fact, such as a payment the provider could not
 * confirm. Once per code and payment: repeated events add nothing.
 */
final class RecordFlags
{
    /** @param array<string, mixed> $detail */
    public static function add(Model $record, string $code, array $detail = []): void
    {
        $locked = $record->newQuery()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
        $flags = (array) ($locked->flags ?? []);

        foreach ($flags as $flag) {
            if (($flag['code'] ?? null) === $code && ($flag['detail'] ?? []) == $detail) {
                return;
            }
        }

        $flags[] = array_filter(['code' => $code, 'detail' => $detail], fn ($value) => $value !== []);
        $locked->forceFill(['flags' => $flags])->saveQuietly();
        $record->setRawAttributes($locked->getAttributes(), true);
    }
}
