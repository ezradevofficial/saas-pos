<?php

namespace Modules\Ecommerce\Catalogue;

use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\ItemImage;
use App\Core\MasterData\Prices\PriceResolver;
use App\Core\MasterData\Taxes\ItemTaxStatus;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Modules\Ecommerce\Models\Product;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Shop\ShopUrls;

/**
 * ECOM-02 to ECOM-05, ECOM-23: the storefront's catalogue: published
 * products of active items, with the list price of the day (ECOM-03; none
 * means unavailable with its reason), availability (ECOM-04), the core
 * item's images behind signed URLs (no tenant token reaches the
 * storefront), categories, and share links. Read under the store's
 * tenant (ResolveShop).
 */
final class ShopCatalogue
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly ItemTaxStatus $taxStatus,
        private readonly Availability $availability,
        private readonly ShopUrls $urls,
    ) {}

    public function published(Store $store): Builder
    {
        return Product::query()->where('ecom_products.store_id', $store->id)->where('ecom_products.published', true)->whereNull('ecom_products.archived_at')
            ->whereHas('item', fn ($q) => $q->whereNull('archived_at')->where('type', '!=', 'kit')
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $store->company_id)));
    }

    /** @return list<array{id: string, name: string, parent_id: ?string}> the categories of published products */
    public function categories(Store $store): array
    {
        $ids = $this->published($store)->join('items', 'items.id', '=', 'ecom_products.item_id')->whereNotNull('items.category_id')->distinct()->pluck('items.category_id');

        return ItemCategory::query()->whereKey($ids)->orderBy('name')->get(['id', 'name', 'parent_id'])
            ->map(fn (ItemCategory $c) => ['id' => $c->id, 'name' => $c->name, 'parent_id' => $c->parent_id])->values()->all();
    }

    /**
     * @param  Collection<int, Product>  $products  with item and item.images
     * @return list<array<string, mixed>>
     */
    public function present(Store $store, Collection $products, bool $detail = false): array
    {
        $company = Company::query()->findOrFail($store->company_id);
        $now = CarbonImmutable::now()->setTimezone($company->timezone ?: 'UTC');
        $list = PriceList::query()->find($store->price_list_id);
        $statuses = $this->taxStatus->forCompany($company, $now);
        $stock = $this->availability->of($store, $products);
        $categories = ItemCategory::query()->whereKey($products->pluck('item.category_id')->filter()->unique())->pluck('name', 'id');

        return $products->map(function (Product $product) use ($store, $list, $now, $statuses, $stock, $categories, $detail) {
            $item = $product->item;
            $price = $list === null ? null : $this->prices->priceFor($item, (string) $item->base_uom_id, $list, $now->toDateString())?->money;
            $tax = ItemTaxStatus::of($item->tax_category_id, $statuses);
            $reason = match (true) {
                $price === null => 'price_missing',
                $tax['tax_code_id'] === null => 'tax_code_missing',
                ! $tax['sellable'] => 'tax_rate_missing',
                default => null,
            };
            $available = $stock[$product->id] ?? ['tracked' => false, 'available' => null, 'in_stock' => true];
            $state = match (true) {
                ! $available['in_stock'] => 'out_of_stock',
                $available['tracked'] && (float) $available['available'] <= Availability::LOW => 'low',
                default => 'in_stock',
            };
            $url = $this->urls->product($store, $product);

            return [
                'id' => $product->id,
                'slug' => $product->slug,
                'name' => $item->name,
                'code' => $item->code,
                'description' => $detail ? $product->description : mb_substr((string) $product->description, 0, 160),
                'featured' => $product->featured,
                'category' => $item->category_id === null ? null : ['id' => $item->category_id, 'name' => $categories[$item->category_id] ?? null],
                'currency' => $store->currency,
                'price_minor' => $price === null ? null : (string) $price->minor(),
                'tax_inclusive' => (bool) ($list?->tax_inclusive ?? false),
                'orderable' => $reason === null && $state !== 'out_of_stock',
                'unavailable_reason' => $reason,
                'availability' => ['state' => $state, 'left' => $state === 'low' ? $available['available'] : null],
                'images' => $item->images->take($detail ? 8 : 1)->map(fn (ItemImage $image) => $this->imageUrl($image))->values()->all(),
                'share' => ['url' => $url, 'whatsapp' => ShopUrls::whatsapp($item->name.' '.$url)],
            ];
        })->values()->all();
    }

    /** A temporary signed address of an item image for the storefront (ShopImageController). */
    public function imageUrl(ItemImage $image): string
    {
        return URL::temporarySignedRoute('ecommerce.shop.image', CarbonImmutable::now()->addHours(12), ['image' => $image->id, 'tenant' => $image->tenant_id]);
    }
}
