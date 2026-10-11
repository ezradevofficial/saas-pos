<?php

namespace App\Core\Layouts\Kinds;

use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Configuration\PayloadSchema;
use App\Core\CustomFields\CustomFieldAccess;
use App\Core\CustomFields\CustomFieldDefinitions;
use App\Core\CustomFields\CustomFieldTypes;
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

    /** Largest list view document, as JSON (bytes). */
    public const MAX_BYTES = 32768;

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
            // A web list's id only (ListViewCatalogue), and a small payload: anyone may keep their own.
            keys: fn (string $key) => app(ListViewCatalogue::class)->has($key),
            maxBytes: self::MAX_BYTES,
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
                // A filter's URL name; custom field filters are `custom[key]` or `custom[key][min]` (CF-02).
                if (preg_match('/^[a-z][a-z0-9_\[\]]{0,99}$/', (string) $name) !== 1 || ! is_string($value) || mb_strlen($value) > 200) {
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

    /**
     * The views as the reader may use them, so a shared view never breaks
     * the list for someone it was not made by:
     *
     * - columns, sorts and filters built from a field the reader's field
     *   rules hide are dropped (RBAC-05), custom fields included
     *   (CustomFieldAccess, `custom[<key>]` filters);
     * - a sort the list no longer has is cleared;
     * - a filter naming a record that is archived or gone is dropped, as is
     *   a custom field filter whose field is no longer filterable.
     *
     * `hidden_columns` lists the hidden column keys so the web drops them too.
     */
    public static function present(array $payload, string $key, User $reader): array
    {
        $catalogue = app(ListViewCatalogue::class);
        $hidden = $catalogue->hiddenFor($key, $reader);
        $payload['hidden_columns'] = $hidden['columns'];
        $definition = $catalogue->definition($key);

        if ($definition === null || ! is_array($payload['views'] ?? null)) {
            return $payload;
        }

        $views = array_filter($payload['views'], 'is_array');
        $used = [];

        foreach ($views as $view) {
            foreach (is_array($view['filters'] ?? null) ? $view['filters'] : [] as $name => $value) {
                $used[(string) $name][] = $value;
            }
        }

        $gone = $catalogue->goneReferences($definition, $used);
        $sorts = array_map('strval', array_keys($definition->sorts()));
        $filterFields = $definition->filterFields();
        $entity = $definition->customFieldEntity();
        $filterable = $entity === null ? [] : array_keys(array_filter(
            app(CustomFieldDefinitions::class)->byKey($entity),
            fn ($field) => $field->archived_at === null && in_array($field->type, CustomFieldTypes::FILTERABLE, true),
        ));

        $payload['views'] = array_map(function ($view) use ($hidden, $sorts, $filterFields, $filterable, $entity, $gone, $definition) {
            if (! is_array($view)) {
                return $view;
            }

            $view['columns'] = array_values(array_filter((array) ($view['columns'] ?? []), fn ($column) => ! in_array(is_array($column) ? ($column['id'] ?? null) : null, $hidden['columns'], true)));

            if (isset($view['sort'])) {
                $sort = ltrim((string) $view['sort'], '-');

                if (! in_array($sort, $sorts, true) || in_array($sort, $hidden['sorts'], true)) {
                    $view['sort'] = null;
                }
            }

            if (is_array($view['filters'] ?? null)) {
                $view['filters'] = array_filter($view['filters'], function ($value, $name) use ($hidden, $filterFields, $filterable, $entity, $gone, $definition) {
                    $name = (string) $name;

                    if (preg_match('/^custom\[([^\]]+)\]/', $name, $match) === 1) {
                        return $entity !== null && in_array($match[1], $filterable, true)
                            && ! in_array(CustomFieldAccess::PREFIX.$match[1], $hidden['fields'], true);
                    }

                    if (isset($filterFields[$name]) && $definition->hides($filterFields[$name], $hidden['fields'])) {
                        return false;
                    }

                    return ! in_array($value, $gone[$name] ?? [], true);
                }, ARRAY_FILTER_USE_BOTH);
            }

            return $view;
        }, $payload['views']);

        return $payload;
    }
}
