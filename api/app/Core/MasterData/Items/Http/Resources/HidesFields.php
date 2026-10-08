<?php

namespace App\Core\MasterData\Items\Http\Resources;

use App\Core\Rbac\FieldRules;
use Illuminate\Http\Request;

/**
 * RBAC-05 for item resources: the fields hidden from the requesting user
 * (read once per request, lists render many records) are left out, and an
 * output key built from several columns is hidden when any of them is.
 */
final class HidesFields
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, list<string>>  $sources  output key => columns it is built from
     * @return array<string, mixed>
     */
    public static function apply(Request $request, string $resource, array $fields, array $sources = []): array
    {
        $hidden = self::hidden($request, $resource);

        if ($hidden === []) {
            return $fields;
        }

        return array_filter($fields, fn (string $key) => ! in_array($key, $hidden, true)
            && array_intersect($sources[$key] ?? [], $hidden) === [], ARRAY_FILTER_USE_KEY);
    }

    /** @return list<string> */
    private static function hidden(Request $request, string $resource): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        $key = "field_rules.{$resource}";

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, app(FieldRules::class)->for($user, $resource)['hidden']);
        }

        return $request->attributes->get($key);
    }
}
