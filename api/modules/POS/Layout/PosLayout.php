<?php

namespace Modules\POS\Layout;

use App\Core\Branding\Models\BrandAsset;
use App\Core\Configuration\CatalogueMerge;
use App\Core\Configuration\ConfigKind;
use App\Core\Configuration\Models\ConfigDocument;
use App\Core\Configuration\PayloadSchema;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\POS\PosServiceProvider;

/**
 * LAY-05: the till's sell screen as versioned configuration (docs/adr/010),
 * kind `pos_layout`, key `default`, per tenant, company, branch or location
 * (the most specific published one wins; a location's wins over its
 * branch's). Registered by the POS module, so it exists only while the
 * tenant has POS (RBAC-08), under `pos.layout.view|edit|publish`.
 *
 *   {
 *     "grid": {"tablet_columns": 3..6, "phone_columns": 2..3, "tile_size": "compact|standard|large"},
 *     "products": {"pinned": [item id, ...], "order": "category|name|best_sellers"},
 *     "categories": [{"id": category id, "hidden": bool, "color": token|null,
 *                     "image": {"source": "item_image|brand_asset", "id": id}|null}],
 *     "quick_buttons": [{"type": "item|category", "id": id} | {"type": "action", "action": "hold|..."}],
 *     "keypad": "right|left",                     the sale panel's side on a tablet; a phone keeps it at the bottom
 *     "customer_display": {"welcome": text|null, "show_lines": bool, "show_second_currency": bool, "show_logo": bool}
 *   }
 *
 * Category colours come from a fixed set of tokens, never a hex (BR-01).
 * The welcome text is typed once (single-language tenant text).
 *
 * LAY-07: when read, a layout is merged with what exists now: categories
 * it does not name (added since) come last, shown; categories, items and
 * images that no longer exist (deleted or archived) are skipped.
 */
final class PosLayout
{
    public const KEY = 'pos_layout';

    public const PERMISSIONS = [
        'view' => 'pos.layout.view',
        'edit' => 'pos.layout.edit',
        'publish' => 'pos.layout.publish',
    ];

    public const SCOPES = [ConfigDocument::TENANT, ConfigDocument::COMPANY, ConfigDocument::BRANCH, ConfigDocument::LOCATION];

    /** Category tile colours: tokens only (BR-01). `accent-tint` is not a design token yet. */
    public const COLOURS = ['primary-tint', 'surface-300', 'success-tint', 'warning-tint', 'danger-tint'];

    public const TILE_SIZES = ['compact', 'standard', 'large'];

    public const ORDERS = ['category', 'name', 'best_sellers'];

    /** What a quick button may do besides adding an item or opening a category. */
    public const ACTIONS = ['hold', 'customer', 'cash_in', 'cash_out', 'sales'];

    public const IMAGE_SOURCES = ['item_image', 'brand_asset'];

    public const MAX_QUICK_BUTTONS = 8;

    public const MAX_PINNED = 48;

    public const WELCOME_MAX = 120;

    /** Days of sales that rank the best-sellers. */
    public const BEST_SELLER_DAYS = 30;

    public const BEST_SELLER_LIMIT = 100;

    public const DEFAULTS = [
        'grid' => ['tablet_columns' => 4, 'phone_columns' => 2, 'tile_size' => 'standard'],
        'products' => ['pinned' => [], 'order' => 'name'],
        'categories' => [],
        'quick_buttons' => [],
        'keypad' => 'right',
        'customer_display' => ['welcome' => null, 'show_lines' => true, 'show_second_currency' => true, 'show_logo' => true],
    ];

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const SCHEMA = [
        'type' => 'object',
        'additional' => false,
        'properties' => [
            'grid' => ['type' => 'object', 'additional' => false, 'properties' => [
                'tablet_columns' => ['type' => 'integer', 'min' => 3, 'max' => 6],
                'phone_columns' => ['type' => 'integer', 'min' => 2, 'max' => 3],
                'tile_size' => ['type' => 'string', 'enum' => self::TILE_SIZES],
            ]],
            'products' => ['type' => 'object', 'additional' => false, 'properties' => [
                'pinned' => ['type' => 'array', 'max' => self::MAX_PINNED, 'items' => ['type' => 'string', 'pattern' => self::UUID]],
                'order' => ['type' => 'string', 'enum' => self::ORDERS],
            ]],
            'categories' => ['type' => 'array', 'max' => 500, 'unique' => 'id', 'items' => [
                'type' => 'object',
                'required' => ['id'],
                'additional' => false,
                'properties' => [
                    'id' => ['type' => 'string', 'pattern' => self::UUID],
                    'hidden' => ['type' => 'boolean'],
                    'color' => ['type' => 'string', 'nullable' => true, 'enum' => self::COLOURS],
                    'image' => ['type' => 'object', 'nullable' => true, 'required' => ['source', 'id'], 'additional' => false, 'properties' => [
                        'source' => ['type' => 'string', 'enum' => self::IMAGE_SOURCES],
                        'id' => ['type' => 'string', 'pattern' => self::UUID],
                    ]],
                ],
            ]],
            'quick_buttons' => ['type' => 'array', 'max' => self::MAX_QUICK_BUTTONS, 'items' => [
                'type' => 'object',
                'required' => ['type'],
                'additional' => false,
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['item', 'category', 'action']],
                    'id' => ['type' => 'string', 'nullable' => true, 'pattern' => self::UUID],
                    'action' => ['type' => 'string', 'nullable' => true, 'enum' => self::ACTIONS],
                ],
            ]],
            'keypad' => ['type' => 'string', 'enum' => ['right', 'left']],
            'customer_display' => ['type' => 'object', 'additional' => false, 'properties' => [
                'welcome' => ['type' => 'string', 'nullable' => true, 'max' => self::WELCOME_MAX],
                'show_lines' => ['type' => 'boolean'],
                'show_second_currency' => ['type' => 'boolean'],
                'show_logo' => ['type' => 'boolean'],
            ]],
        ],
    ];

    public static function kind(): ConfigKind
    {
        return new ConfigKind(
            key: self::KEY,
            schema: fn (array $payload) => self::problems($payload),
            scopes: self::SCOPES,
            permissions: self::PERMISSIONS,
            // LAY-07: read (resolved) against the tenant's catalogue; a till gets
            // its own company's (PosLayoutSource).
            merger: fn (array $payload) => self::merge($payload, PosLayoutCatalogue::forTenant()),
            defaults: fn () => self::DEFAULTS,
            keys: [ConfigKind::DEFAULT_KEY],
            module: PosServiceProvider::MODULE,
            layoutKeys: ['color', 'image'],
            maxBytes: 65536,
        );
    }

    /**
     * What keeps a layout from being published (LAY-06): its shape, then
     * what it names. Quick buttons say what they open; every category, item
     * and image named must be the tenant's and not archived (another
     * tenant's ids are never found, TEN-01).
     *
     * @return list<array{path: string, code: string, message: string}>
     */
    public static function problems(array $payload): array
    {
        $problems = PayloadSchema::check($payload, self::SCHEMA);

        if ($problems !== []) {
            return $problems;
        }

        $pinned = $payload['products']['pinned'] ?? [];
        $seen = [];

        foreach ($pinned as $index => $id) {
            if (isset($seen[strtolower($id)])) {
                $problems[] = PayloadSchema::problem("products.pinned.{$index}", 'duplicate', ['value' => $id]);
            }

            $seen[strtolower($id)] = true;
        }

        $buttons = $payload['quick_buttons'] ?? [];
        $keys = [];

        foreach ($buttons as $index => $button) {
            $type = $button['type'];
            $target = $type === 'action' ? ($button['action'] ?? null) : ($button['id'] ?? null);

            if ($target === null) {
                $problems[] = PayloadSchema::problem("quick_buttons.{$index}.".($type === 'action' ? 'action' : 'id'), 'required');

                continue;
            }

            if (($type === 'action' && isset($button['id'])) || ($type !== 'action' && isset($button['action']))) {
                $problems[] = PayloadSchema::problem("quick_buttons.{$index}", 'pos_layout_button');
            }

            $key = $type.':'.strtolower($target);

            if (isset($keys[$key])) {
                $problems[] = PayloadSchema::problem("quick_buttons.{$index}", 'duplicate', ['value' => $target]);
            }

            $keys[$key] = true;
        }

        return [...$problems, ...self::unknownReferences($payload)];
    }

    /**
     * Ids the layout names that are not (or no longer) the tenant's.
     *
     * @return list<array{path: string, code: string, message: string}>
     */
    private static function unknownReferences(array $payload): array
    {
        $items = array_values(array_unique([
            ...array_map('strtolower', $payload['products']['pinned'] ?? []),
            ...array_map(fn ($b) => strtolower($b['id']), array_filter($payload['quick_buttons'] ?? [], fn ($b) => $b['type'] === 'item' && is_string($b['id'] ?? null))),
        ]));
        $categories = array_values(array_unique([
            ...array_map(fn ($c) => strtolower($c['id']), $payload['categories'] ?? []),
            ...array_map(fn ($b) => strtolower($b['id']), array_filter($payload['quick_buttons'] ?? [], fn ($b) => $b['type'] === 'category' && is_string($b['id'] ?? null))),
        ]));
        $images = collect($payload['categories'] ?? [])->pluck('image')->filter()->groupBy('source')->map(fn ($group) => $group->pluck('id')->map(fn ($id) => strtolower($id))->unique()->values()->all());

        $db = DB::connection(TenantContext::CONNECTION);
        $knownItems = $items === [] ? [] : array_flip($db->table('items')->whereIn('id', $items)->whereNull('archived_at')->pluck('id')->all());
        $knownCategories = $categories === [] ? [] : array_flip($db->table('item_categories')->whereIn('id', $categories)->whereNull('archived_at')->pluck('id')->all());
        $knownItemImages = ($images['item_image'] ?? []) === [] ? [] : array_flip($db->table('item_images')->whereIn('id', $images['item_image'])->pluck('id')->all());
        $knownAssets = ($images['brand_asset'] ?? []) === [] ? [] : array_flip(BrandAsset::query()->whereIn('id', $images['brand_asset'])->where('kind', '!=', BrandAsset::FAVICON)->pluck('id')->all());

        $problems = [];

        foreach ($payload['products']['pinned'] ?? [] as $index => $id) {
            if (! isset($knownItems[strtolower($id)])) {
                $problems[] = PayloadSchema::problem("products.pinned.{$index}", 'pos_layout_unknown_item');
            }
        }

        foreach ($payload['categories'] ?? [] as $index => $category) {
            if (! isset($knownCategories[strtolower($category['id'])])) {
                $problems[] = PayloadSchema::problem("categories.{$index}.id", 'pos_layout_unknown_category');
            }

            $image = $category['image'] ?? null;

            if ($image !== null) {
                $known = $image['source'] === 'item_image' ? $knownItemImages : $knownAssets;

                if (! isset($known[strtolower($image['id'])])) {
                    $problems[] = PayloadSchema::problem("categories.{$index}.image", 'pos_layout_unknown_image');
                }
            }
        }

        foreach ($payload['quick_buttons'] ?? [] as $index => $button) {
            $known = match ($button['type']) {
                'item' => $knownItems,
                'category' => $knownCategories,
                default => null,
            };

            if ($known !== null && is_string($button['id'] ?? null) && ! isset($known[strtolower($button['id'])])) {
                $problems[] = PayloadSchema::problem("quick_buttons.{$index}.id", $button['type'] === 'item' ? 'pos_layout_unknown_item' : 'pos_layout_unknown_category');
            }
        }

        return $problems;
    }

    /**
     * LAY-07: $payload in full (defaults for what it leaves out), merged
     * with $catalogue: categories in the layout's order, then those it does
     * not name (in the catalogue's order, shown); categories, items and
     * images that no longer exist skipped. Malformed parts fall back to
     * their defaults, never fail.
     */
    public static function merge(array $payload, PosLayoutCatalogue $catalogue): array
    {
        $catalogue->prime($payload);
        $grid = is_array($payload['grid'] ?? null) ? $payload['grid'] : [];
        $products = is_array($payload['products'] ?? null) ? $payload['products'] : [];
        $display = is_array($payload['customer_display'] ?? null) ? $payload['customer_display'] : [];
        $pick = fn (mixed $value, array $allowed, mixed $default) => in_array($value, $allowed, true) ? $value : $default;
        $within = fn (mixed $value, int $min, int $max, int $default) => is_int($value) && $value >= $min && $value <= $max ? $value : $default;
        $bool = fn (mixed $value, bool $default) => is_bool($value) ? $value : $default;

        $categories = array_map(function (array $entry) use ($catalogue) {
            $image = is_array($entry['image'] ?? null) ? $entry['image'] : null;

            return [
                'id' => (string) $entry['id'],
                'hidden' => ($entry['hidden'] ?? false) === true,
                'color' => in_array($entry['color'] ?? null, self::COLOURS, true) ? $entry['color'] : null,
                'image' => $image !== null && $catalogue->hasImage($image['source'] ?? null, $image['id'] ?? null)
                    ? ['source' => $image['source'], 'id' => strtolower($image['id'])]
                    : null,
            ];
        }, CatalogueMerge::entries(
            self::lowerIds(is_array($payload['categories'] ?? null) ? $payload['categories'] : []),
            array_map(fn (string $id) => ['id' => $id], $catalogue->categoryIds),
            CatalogueMerge::APPEND,
            layoutKeys: ['hidden', 'color', 'image'],
        ));

        $pinned = [];

        foreach (is_array($products['pinned'] ?? null) ? $products['pinned'] : [] as $id) {
            if (is_string($id) && $catalogue->hasItem($id) && ! in_array(strtolower($id), $pinned, true)) {
                $pinned[] = strtolower($id);
            }
        }

        $buttons = [];

        foreach (is_array($payload['quick_buttons'] ?? null) ? $payload['quick_buttons'] : [] as $button) {
            $type = is_array($button) ? ($button['type'] ?? null) : null;
            $button = match (true) {
                $type === 'action' && in_array($button['action'] ?? null, self::ACTIONS, true) => ['type' => 'action', 'action' => $button['action']],
                $type === 'item' && is_string($button['id'] ?? null) && $catalogue->hasItem($button['id']) => ['type' => 'item', 'id' => strtolower($button['id'])],
                $type === 'category' && is_string($button['id'] ?? null) && $catalogue->hasCategory($button['id']) => ['type' => 'category', 'id' => strtolower($button['id'])],
                default => null,
            };

            if ($button !== null && ! in_array($button, $buttons, true) && count($buttons) < self::MAX_QUICK_BUTTONS) {
                $buttons[] = $button;
            }
        }

        $welcome = $display['welcome'] ?? null;

        return [
            'grid' => [
                'tablet_columns' => $within($grid['tablet_columns'] ?? null, 3, 6, self::DEFAULTS['grid']['tablet_columns']),
                'phone_columns' => $within($grid['phone_columns'] ?? null, 2, 3, self::DEFAULTS['grid']['phone_columns']),
                'tile_size' => $pick($grid['tile_size'] ?? null, self::TILE_SIZES, self::DEFAULTS['grid']['tile_size']),
            ],
            'products' => [
                'pinned' => array_slice($pinned, 0, self::MAX_PINNED),
                'order' => $pick($products['order'] ?? null, self::ORDERS, self::DEFAULTS['products']['order']),
            ],
            'categories' => $categories,
            'quick_buttons' => $buttons,
            'keypad' => $pick($payload['keypad'] ?? null, ['right', 'left'], self::DEFAULTS['keypad']),
            'customer_display' => [
                'welcome' => is_string($welcome) && trim($welcome) !== '' ? Str::limit($welcome, self::WELCOME_MAX, '') : null,
                'show_lines' => $bool($display['show_lines'] ?? null, true),
                'show_second_currency' => $bool($display['show_second_currency'] ?? null, true),
                'show_logo' => $bool($display['show_logo'] ?? null, true),
            ],
        ];
    }

    /** Stored category entries with lower-cased ids (UUIDs are compared in lower case). */
    private static function lowerIds(array $entries): array
    {
        return array_map(fn ($entry) => is_array($entry) && is_string($entry['id'] ?? null) ? [...$entry, 'id' => strtolower($entry['id'])] : $entry, $entries);
    }
}
