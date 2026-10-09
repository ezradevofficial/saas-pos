<?php

namespace App\Core\CustomFields;

use Closure;
use Illuminate\Http\Request;

/**
 * Values kept for one HTTP request (definitions, a user's field access,
 * lookup labels): lists render many records and ask for the same things
 * for each. Outside a request (a worker's request object lives across
 * jobs) nothing is kept.
 */
final class RequestCache
{
    public const PREFIX = 'custom_fields.';

    public static function remember(string $key, Closure $compute): mixed
    {
        $request = self::request();

        if ($request === null) {
            return $compute();
        }

        $key = self::PREFIX.$key;

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $compute());
        }

        return $request->attributes->get($key);
    }

    public static function forget(): void
    {
        $request = self::request();

        foreach (array_keys($request?->attributes->all() ?? []) as $key) {
            if (str_starts_with((string) $key, self::PREFIX)) {
                $request->attributes->remove($key);
            }
        }
    }

    private static function request(): ?Request
    {
        if (! app()->bound('request') || (app()->runningInConsole() && ! app()->runningUnitTests())) {
            return null;
        }

        return app('request');
    }
}
