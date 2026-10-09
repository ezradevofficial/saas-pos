<?php

namespace App\Core\Layouts\Kinds;

use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Configuration\PayloadSchema;
use App\Core\Layouts\LayoutsServiceProvider;

/**
 * LAY-02: the sidebar of a role (or the tenant's default for every role):
 * its groups in order (renamed or not), the items of each in order
 * (renamed, hidden or moved between groups) and the role's home page.
 * Items are named by their route. Names are typed once, not translated.
 *
 * LAY-07: the catalogue is the web's navigation, so the web merges the
 * layout with it (CatalogueMerge semantics: a new item appears in its
 * default group, an item that no longer exists is skipped). Hiding an
 * item never grants one: the web filters by permissions and modules
 * afterwards (RBAC-09), and the API checks every request.
 */
final class NavigationLayout
{
    public const KEY = 'navigation';

    public const ROUTE = '/^\/[a-z0-9\/_-]{0,120}$/';

    public const SCHEMA = [
        'type' => 'object',
        'required' => ['groups'],
        'additional' => false,
        'properties' => [
            'groups' => ['type' => 'array', 'max' => 30, 'unique' => 'id', 'items' => [
                'type' => 'object',
                'required' => ['id', 'items'],
                'additional' => false,
                'properties' => [
                    'id' => ['type' => 'string', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/'],
                    'label' => ['type' => 'string', 'nullable' => true, 'min' => 1, 'max' => 60],
                    'items' => ['type' => 'array', 'max' => 80, 'unique' => 'id', 'items' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'additional' => false,
                        'properties' => [
                            'id' => ['type' => 'string', 'pattern' => self::ROUTE],
                            'label' => ['type' => 'string', 'nullable' => true, 'min' => 1, 'max' => 60],
                            'hidden' => ['type' => 'boolean'],
                        ],
                    ]],
                ],
            ]],
            'home' => ['type' => 'string', 'nullable' => true, 'pattern' => self::ROUTE],
        ],
    ];

    public static function kind(): ConfigKind
    {
        return new ConfigKind(
            key: self::KEY,
            schema: fn (array $payload) => self::problems($payload),
            scopes: [ConfigDocument::TENANT, ConfigDocument::ROLE],
            permissions: LayoutsServiceProvider::PERMISSIONS,
            keys: [ConfigKind::DEFAULT_KEY],
        );
    }

    /** @return list<array{path: string, code: string, message: string}> */
    public static function problems(array $payload): array
    {
        $problems = PayloadSchema::check($payload, self::SCHEMA);
        $seen = [];

        foreach ((array) ($payload['groups'] ?? []) as $g => $group) {
            foreach (is_array($group) ? (array) ($group['items'] ?? []) : [] as $i => $item) {
                $id = is_array($item) && is_string($item['id'] ?? null) ? $item['id'] : null;

                if ($id === null) {
                    continue;
                }

                if (isset($seen[$id])) {
                    $problems[] = PayloadSchema::problem("groups.{$g}.items.{$i}.id", 'duplicate', ['value' => $id]);
                }

                $seen[$id] = true;
            }
        }

        return $problems;
    }
}
