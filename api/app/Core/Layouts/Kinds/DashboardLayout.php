<?php

namespace App\Core\Layouts\Kinds;

use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Configuration\PayloadSchema;
use App\Core\Identity\Models\User;
use App\Core\Layouts\Dashboards\DashboardSource;
use App\Core\Layouts\Dashboards\DashboardSources;
use App\Core\Layouts\LayoutsServiceProvider;

/**
 * LAY-01: a dashboard on a 12-column grid: widgets (kpi, chart, list,
 * shortcut, approval_count), each bound to a registered data source with
 * its parameters, placed at column `x` (0-11) and row `y`, `w` columns
 * wide and `h` rows high. Per tenant, per role, or a user's personal copy
 * (any user may keep their own, `personal`). Titles are typed once.
 *
 * - Publishing refuses widgets off the grid, overlapping widgets, unknown
 *   sources, a source that can't feed the widget type and parameters the
 *   source refuses.
 * - LAY-07: on reading, a widget of an unknown type, or whose source no
 *   longer exists or whose module is off (RBAC-08), is skipped.
 * - RBAC-09: the presenter drops widgets whose source the reader can't
 *   open; the source answers only what the reader reaches (RBAC-04).
 */
final class DashboardLayout
{
    public const KEY = 'dashboard';

    public const COLUMNS = 12;

    public const MAX_HEIGHT = 8;

    public const SCHEMA = [
        'type' => 'object',
        'required' => ['widgets'],
        'additional' => false,
        'properties' => [
            'title' => ['type' => 'string', 'nullable' => true, 'max' => 80],
            'widgets' => ['type' => 'array', 'max' => 24, 'unique' => 'id', 'items' => [
                'type' => 'object',
                'required' => ['id', 'type', 'source', 'x', 'y', 'w', 'h'],
                'additional' => false,
                'properties' => [
                    'id' => ['type' => 'string', 'pattern' => '/^[a-z0-9][a-z0-9_-]{0,39}$/'],
                    'type' => ['type' => 'string', 'enum' => DashboardSource::WIDGETS],
                    'source' => ['type' => 'string', 'pattern' => '/^[a-z][a-z0-9_.]{0,59}$/'],
                    'params' => ['type' => 'object'],
                    'title' => ['type' => 'string', 'nullable' => true, 'max' => 80],
                    'chart' => ['type' => 'string', 'nullable' => true, 'enum' => ['bar', 'line']],
                    'x' => ['type' => 'integer', 'min' => 0, 'max' => self::COLUMNS - 1],
                    'y' => ['type' => 'integer', 'min' => 0, 'max' => 199],
                    'w' => ['type' => 'integer', 'min' => 1, 'max' => self::COLUMNS],
                    'h' => ['type' => 'integer', 'min' => 1, 'max' => self::MAX_HEIGHT],
                ],
            ]],
        ],
    ];

    /** The dashboard when none is published: getting started and the reader's approvals. */
    public const DEFAULT = [
        'title' => null,
        'widgets' => [
            ['id' => 'start', 'type' => DashboardSource::SHORTCUT, 'source' => 'shortcuts', 'params' => ['links' => ['/settings/organisation', '/settings/users', '/settings/appearance']], 'title' => null, 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4],
            ['id' => 'waiting', 'type' => DashboardSource::APPROVAL_COUNT, 'source' => 'approvals.waiting', 'params' => [], 'title' => null, 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 1],
            ['id' => 'mine', 'type' => DashboardSource::LIST, 'source' => 'approvals.mine', 'params' => ['limit' => 5], 'title' => null, 'x' => 6, 'y' => 1, 'w' => 6, 'h' => 3],
        ],
    ];

    public static function kind(): ConfigKind
    {
        return new ConfigKind(
            key: self::KEY,
            schema: fn (array $payload) => self::problems($payload),
            scopes: [ConfigDocument::TENANT, ConfigDocument::ROLE, ConfigDocument::USER],
            permissions: LayoutsServiceProvider::PERMISSIONS,
            merger: fn (array $payload) => self::merge($payload),
            defaults: fn () => self::DEFAULT,
            keys: [ConfigKind::DEFAULT_KEY],
            personal: true,
            presenter: fn (array $payload, string $key, User $reader) => self::present($payload, $reader),
        );
    }

    /** @return list<array{path: string, code: string, message: string}> */
    public static function problems(array $payload): array
    {
        $problems = PayloadSchema::check($payload, self::SCHEMA);

        if ($problems !== []) {
            return $problems;
        }

        $sources = app(DashboardSources::class);
        $cells = [];

        foreach ($payload['widgets'] as $index => $widget) {
            $path = "widgets.{$index}";

            if ($widget['x'] + $widget['w'] > self::COLUMNS) {
                $problems[] = PayloadSchema::problem("{$path}.w", 'off_grid', ['columns' => self::COLUMNS]);

                continue;
            }

            for ($row = $widget['y']; $row < $widget['y'] + $widget['h']; $row++) {
                for ($column = $widget['x']; $column < $widget['x'] + $widget['w']; $column++) {
                    if (isset($cells["{$row}:{$column}"])) {
                        $problems[] = PayloadSchema::problem($path, 'overlap', ['value' => $cells["{$row}:{$column}"]]);

                        continue 3;
                    }

                    $cells["{$row}:{$column}"] = $widget['id'];
                }
            }

            $source = $sources->find($widget['source']);

            if ($source === null) {
                $problems[] = PayloadSchema::problem("{$path}.source", 'unknown_source', ['value' => $widget['source']]);
            } elseif (! $source->feeds($widget['type'])) {
                $problems[] = PayloadSchema::problem("{$path}.type", 'source_widget', ['value' => $widget['type']]);
            } else {
                foreach ($sources->paramProblems($source, (array) ($widget['params'] ?? [])) as $field => $message) {
                    $problems[] = ['path' => "{$path}.params.{$field}", 'code' => 'params', 'message' => $message];
                }
            }
        }

        return $problems;
    }

    /** LAY-07: widgets of an unknown type, or of a source that is gone or switched off, are skipped. */
    public static function merge(array $payload): array
    {
        $sources = app(DashboardSources::class);

        $payload['widgets'] = array_values(array_filter((array) ($payload['widgets'] ?? []), function ($widget) use ($sources) {
            if (! is_array($widget) || ! in_array($widget['type'] ?? null, DashboardSource::WIDGETS, true) || ! is_string($widget['source'] ?? null)) {
                return false;
            }

            $source = $sources->find($widget['source']);

            return $source !== null && $source->feeds($widget['type']);
        }));

        return $payload;
    }

    /** RBAC-09: only widgets whose source the reader may open. */
    public static function present(array $payload, User $reader): array
    {
        $sources = app(DashboardSources::class);
        $open = [];

        $payload['widgets'] = array_values(array_filter((array) ($payload['widgets'] ?? []), function (array $widget) use ($sources, $reader, &$open) {
            return $open[$widget['source']] ??= ($sources->find($widget['source'])?->available($reader) ?? false);
        }));

        return $payload;
    }
}
