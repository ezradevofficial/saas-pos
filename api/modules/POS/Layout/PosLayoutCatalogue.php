<?php

namespace Modules\POS\Layout;

use App\Core\Branding\Models\BrandAsset;
use App\Core\Sync\DeviceScope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * LAY-07: what a POS layout can name today, for merging a stored layout
 * (PosLayout::merge): the active categories in their default order (by
 * name), and which of the items and images the layout names still exist.
 * For a till, only what the till holds: its company's and the group's
 * shared categories and items (ItemSource, ItemCategorySource).
 */
final class PosLayoutCatalogue
{
    /** @var array<string, true> */
    private array $items = [];

    /** @var array<string, true> */
    private array $categories;

    /** @var array<string, array<string, true>> */
    private array $images = ['item_image' => [], 'brand_asset' => []];

    /**
     * @param  list<string>  $categoryIds  active categories, in default order
     * @param  (\Closure(Builder): Builder)  $visible  limits a query of `items` (or `item_categories`) to what may be named
     */
    private function __construct(public readonly array $categoryIds, private readonly \Closure $visible)
    {
        $this->categories = array_fill_keys($categoryIds, true);
    }

    /** The tenant's catalogue (row-level security keeps it to the tenant, TEN-01). */
    public static function forTenant(): self
    {
        return self::build(fn (Builder $query) => $query);
    }

    /** What the till at $scope holds: its company's and the shared catalogue. */
    public static function forDevice(DeviceScope $scope): self
    {
        $company = $scope->companyId();

        return self::build(fn (Builder $query) => $query->where(fn (Builder $q) => $q->whereNull($query->from.'.company_id')->orWhere($query->from.'.company_id', $company)));
    }

    private static function build(\Closure $visible): self
    {
        $ids = $visible(self::db()->table('item_categories'))
            ->whereNull('archived_at')
            ->orderBy('name')->orderBy('id')
            ->pluck('id')->all();

        return new self($ids, $visible);
    }

    /** Loads, in a few queries, which of the items and images $payload names still exist. */
    public function prime(array $payload): self
    {
        $ids = fn (mixed $list) => array_values(array_unique(array_map('strtolower', array_filter(is_array($list) ? $list : [], 'is_string'))));
        $buttons = is_array($payload['quick_buttons'] ?? null) ? array_filter($payload['quick_buttons'], 'is_array') : [];
        $items = $ids([
            ...(is_array($payload['products']['pinned'] ?? null) ? $payload['products']['pinned'] : []),
            ...array_map(fn ($b) => $b['id'] ?? null, array_filter($buttons, fn ($b) => ($b['type'] ?? null) === 'item')),
        ]);

        if ($items !== []) {
            $this->items = array_fill_keys(($this->visible)(self::db()->table('items'))->whereIn('id', $items)->whereNull('archived_at')->pluck('id')->all(), true);
        }

        $images = ['item_image' => [], 'brand_asset' => []];

        foreach (is_array($payload['categories'] ?? null) ? $payload['categories'] : [] as $category) {
            $image = is_array($category) && is_array($category['image'] ?? null) ? $category['image'] : null;

            if ($image !== null && isset($images[$image['source'] ?? '']) && is_string($image['id'] ?? null)) {
                $images[$image['source']][] = strtolower($image['id']);
            }
        }

        if ($images['item_image'] !== []) {
            // An item image is shown only while its item may be named here.
            $visibleItems = ($this->visible)(self::db()->table('items'))->whereNull('archived_at')->select('id');
            $this->images['item_image'] = array_fill_keys(self::db()->table('item_images')->whereIn('id', $ids($images['item_image']))->whereIn('item_id', $visibleItems)->pluck('id')->all(), true);
        }

        if ($images['brand_asset'] !== []) {
            $this->images['brand_asset'] = array_fill_keys(BrandAsset::query()->whereIn('id', $ids($images['brand_asset']))->where('kind', '!=', BrandAsset::FAVICON)->pluck('id')->all(), true);
        }

        return $this;
    }

    public function hasItem(string $id): bool
    {
        return isset($this->items[strtolower($id)]);
    }

    public function hasCategory(string $id): bool
    {
        return isset($this->categories[strtolower($id)]);
    }

    public function hasImage(mixed $source, mixed $id): bool
    {
        return is_string($source) && is_string($id) && isset($this->images[$source][strtolower($id)]);
    }

    private static function db(): ConnectionInterface
    {
        return DB::connection(TenantContext::CONNECTION);
    }
}
