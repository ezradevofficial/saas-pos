<?php

namespace Tests\Support\Configuration;

use App\Core\Configuration\CatalogueMerge;
use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\ConfigKinds;
use App\Core\Configuration\Models\ConfigDocument;

/**
 * LAY-06, LAY-07: a configuration kind for tests, like a list view: a
 * payload of columns, every scope type allowed, merged with a catalogue of
 * columns the test can change (as a platform update would).
 */
final class TestLayoutKind
{
    public const KEY = 'test_layout';

    /** @var list<array<string, mixed>> */
    public static array $catalogue = [];

    public const SCHEMA = [
        'type' => 'object',
        'required' => ['columns'],
        'properties' => [
            'columns' => ['type' => 'array', 'max' => 20, 'unique' => 'id', 'items' => [
                'type' => 'object',
                'required' => ['id'],
                'properties' => ['id' => ['type' => 'string', 'max' => 40], 'width' => ['type' => 'integer', 'min' => 1, 'max' => 12]],
            ]],
        ],
    ];

    public static function register(string $module = 'core'): ConfigKind
    {
        self::$catalogue = [['id' => 'name', 'width' => 4], ['id' => 'code', 'width' => 2], ['id' => 'price', 'width' => 2]];

        $kind = new ConfigKind(
            key: self::KEY,
            schema: self::SCHEMA,
            scopes: ConfigDocument::SCOPES,
            merger: fn (array $payload) => [...$payload, 'columns' => CatalogueMerge::entries((array) ($payload['columns'] ?? []), self::$catalogue)],
            defaults: fn () => ['columns' => []],
            module: $module,
        );

        app(ConfigKinds::class)->register($kind);

        return $kind;
    }
}
