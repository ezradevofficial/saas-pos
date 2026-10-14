<?php

namespace Modules\Accounting\Http\Resources;

use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** How Accounting resources render money (ADR 003: digit strings with the currency) and small references. */
final class Shapes
{
    /** @return array{amount_minor: string, currency: string}|null */
    public static function money(int|string|null $minor, ?string $currency): ?array
    {
        return $minor === null || $currency === null ? null : ['amount_minor' => (string) $minor, 'currency' => $currency];
    }

    /** @return array{id: string, name: string}|null */
    public static function named(?Model $model): ?array
    {
        return $model === null ? null : ['id' => (string) $model->getKey(), 'name' => (string) $model->getAttribute('name')];
    }

    /** @return array{id: string, name: string}|null */
    public static function user(?string $id): ?array
    {
        if ($id === null) {
            return null;
        }

        $name = User::query()->whereKey($id)->value('name');

        return $name === null ? null : ['id' => $id, 'name' => $name];
    }

    /**
     * ACC-17: where a journal came from, for drill-down to its source.
     *
     * @return array{type: string, id: string}|null
     */
    public static function source(?string $type, ?string $id): ?array
    {
        return $type === null || $id === null ? null : ['type' => $type, 'id' => $id];
    }
}
