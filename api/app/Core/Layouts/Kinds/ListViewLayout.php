<?php

namespace App\Core\Layouts\Kinds;

use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Configuration\PayloadSchema;
use App\Core\Identity\Models\User;
use App\Core\Layouts\LayoutsServiceProvider;
use App\Core\Layouts\ListViewCatalogue;

/**
 * LAY-04: the saved views of one list (the key is the web list's id, e.g.
 * `items`). A tenant document holds the views everyone gets, a role
 * document those shared with the role, a user document the user's
 * personal views (any user may keep their own, `personal`). Each view
 * names its columns in order (shown or not), its default filters, sort
 * and rows per page; `default_view` is the one a reader starts with.
 *
 * LAY-07: the column catalogue is the web page's, so the web merges a
 * view with it (new columns at the end, shown or hidden per the column's
 * `defaultHidden`, unknown ones skipped). RBAC-05: the presenter drops
 * every column and sort built from a field the reader's rules hide, and
 * lists the hidden column keys so the web drops them too.
 */
final class ListViewLayout
{
    public const KEY = 'list_view';

    private const ID = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

    private const COLUMN = '/^[a-z0-9][a-z0-9_.-]{0,63}$/';

    public const SCHEMA = [
        'type' => 'object',
        'required' => ['views'],
        'additional' => false,
        'properties' => [
            'views' => ['type' => 'array', 'max' => 20, 'unique' => 'id', 'items' => [
                'type' => 'object',
                'required' => ['id', 'name', 'columns'],
                'additional' => false,
                'properties' => [
                    'id' => ['type' => 'string', 'pattern' => self::ID],
                    'name' => ['type' => 'string', 'min' => 1, 'max' => 80],
                    'columns' => ['type' => 'array', 'max' => 60, 'unique' => 'id', 'items' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'additional' => false,
                        'properties' => [
                            'id' => ['type' => 'string', 'pattern' => self::COLUMN],
                            'visible' => ['type' => 'boolean'],
                        ],
                    ]],
                    'filters' => ['type' => 'object'],
                    'sort' => ['type' => 'string', 'nullable' => true, 'pattern' => '/^-?[a-z0-9_]{1,64}$/'],
                    'per_page' => ['type' => 'integer', 'nullable' => true, 'enum' => [10, 25, 50, 100]],
                ],
            ]],
            'default_view' => ['type' => 'string', 'nullable' => true, 'pattern' => self::ID],
        ],
    ];

    public static function kind(): ConfigKind
    {
        return new ConfigKind(
            key: self::KEY,
            schema: fn (array $payload) => self::problems($payload),
            scopes: [ConfigDocument::TENANT, ConfigDocument::ROLE, ConfigDocument::USER],
            permissions: LayoutsServiceProvider::PERMISSIONS,
            defaults: fn () => ['views' => [], 'default_view' => null],
            personal: true,
            presenter: fn (array $payload, string $key, User $reader) => self::present($payload, $key, $reader),
        );
    }

    /** @return list<array{path: string, code: string, message: string}> */
    public static function problems(array $payload): array
    {
        $problems = PayloadSchema::check($payload, self::SCHEMA);
        $views = is_array($payload['views'] ?? null) ? $payload['views'] : [];

        foreach ($views as $index => $view) {
            $filters = is_array($view) && is_array($view['filters'] ?? null) ? $view['filters'] : [];

            if (count($filters) > 20) {
                $problems[] = PayloadSchema::problem("views.{$index}.filters", 'max_items', ['max' => 20]);
            }

            foreach ($filters as $name => $value) {
                if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', (string) $name) !== 1 || ! is_string($value) || mb_strlen($value) > 200) {
                    $problems[] = PayloadSchema::problem("views.{$index}.filters.{$name}", 'filter_value');
                }
            }
        }

        $default = $payload['default_view'] ?? null;

        if (is_string($default) && ! in_array($default, array_map(fn ($view) => is_array($view) ? ($view['id'] ?? null) : null, $views), true)) {
            $problems[] = PayloadSchema::problem('default_view', 'unknown_view', ['value' => $default]);
        }

        return $problems;
    }

    /** RBAC-05: the views without what the reader's field rules hide. */
    public static function present(array $payload, string $key, User $reader): array
    {
        $hidden = app(ListViewCatalogue::class)->hiddenFor($key, $reader);
        $payload['hidden_columns'] = $hidden['columns'];

        if ($hidden['columns'] === [] && $hidden['sorts'] === []) {
            return $payload;
        }

        $payload['views'] = array_map(function ($view) use ($hidden) {
            if (! is_array($view)) {
                return $view;
            }

            $view['columns'] = array_values(array_filter((array) ($view['columns'] ?? []), fn ($column) => ! in_array($column['id'] ?? null, $hidden['columns'], true)));

            if (isset($view['sort']) && in_array(ltrim((string) $view['sort'], '-'), $hidden['sorts'], true)) {
                $view['sort'] = null;
            }

            return $view;
        }, (array) ($payload['views'] ?? []));

        return $payload;
    }
}
