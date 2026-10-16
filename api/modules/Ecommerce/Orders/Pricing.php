<?php

namespace Modules\Ecommerce\Orders;

use App\Core\Currency\Money;
use App\Core\Http\ApiErrorRenderer;
use App\Core\Http\ApiException;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Prices\PriceResolver;
use App\Core\MasterData\Taxes\ItemTaxStatus;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\MasterData\Taxes\TaxCalculator;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRateMissing;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Ecommerce\Catalogue\Availability;
use Modules\Ecommerce\Models\Product;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Models\Zone;
use Modules\Ecommerce\Support\Amounts;
use Modules\Loyalty\Contracts\OnlineLoyalty;

/**
 * ECOM-09 to ECOM-12: a cart priced by the server, in minor units of the
 * store's currency (ADR 003, never floats). Nothing the client says about
 * money is read: it names products, quantities and choices.
 *
 * Per line: the product (published, of the store, its item active) in its
 * item's base unit; the price from the store's price list on the day
 * (PriceResolver, quantity breaks); gross = round_half_up(qty × price);
 * the Loyalty promotions' discount (ECOM-33); amount = gross − discount;
 * the tax code from the item's tax category (ItemTaxStatus, never typed)
 * and its rate at the order time in the company's time zone, inclusive or
 * exclusive per the price list (core calculator). A product without a
 * price, a code or a confirmed rate is unavailable with the reason; rates
 * are never invented.
 *
 * The delivery fee: the zone's, free at or above its `free_over` goods
 * total, taxed with the store's delivery tax code when set (none: no tax).
 * Totals: subtotal = Σ gross, discount = Σ discount, tax = Σ line tax +
 * fee tax, total = Σ line totals + the fee's total. Points and a gift card
 * pay first (in the store's currency only); `due` is what is left.
 *
 * `problems` lists what stops the order (per line or for the cart);
 * strict() throws the first one.
 */
final class Pricing
{
    public function __construct(
        private readonly PriceResolver $prices,
        private readonly TaxCalculator $tax,
        private readonly ItemTaxStatus $taxStatus,
        private readonly Availability $availability,
    ) {}

    /**
     * @param  array{lines: list<array{product_id: string, qty: string}>, fulfilment?: ?string, zone_id?: ?string, pickup_location_id?: ?string, coupon?: ?string, points?: ?string, gift_card?: ?string}  $cart
     * @return array<string, mixed>
     */
    public function quote(Store $store, array $cart, ?string $partyId, CarbonImmutable $at): array
    {
        $company = Company::query()->findOrFail($store->company_id);
        $list = PriceList::query()->find($store->price_list_id);
        $inclusive = (bool) ($list?->tax_inclusive ?? false);
        $zone = $company->timezone ?: 'UTC';
        $local = $at->setTimezone($zone);
        $statuses = $this->taxStatus->forCompany($company, $local);
        $problems = [];
        $problem = function (string $code, ?int $line = null, array $replace = []) use (&$problems): void {
            $problems[] = array_filter(['code' => $code, 'line' => $line, 'message' => __("ecommerce.errors.{$code}", $replace)], fn ($v) => $v !== null);
        };

        $inputs = array_values($cart['lines'] ?? []);
        $ids = array_values(array_filter(array_map(fn ($line) => (string) ($line['product_id'] ?? ''), $inputs), fn ($id) => Str::isUuid($id)));
        $products = Product::query()->where('store_id', $store->id)->whereKey($ids)->where('published', true)->whereNull('archived_at')
            ->with('item')->get()->keyBy('id');
        $stock = $this->availability->of($store, $products->values());
        $uoms = Uom::query()->whereIn('id', $products->pluck('item.base_uom_id')->filter()->unique())->get()->keyBy('id');
        $lines = [];
        $wanted = [];

        foreach ($inputs as $index => $input) {
            $product = $products->get((string) ($input['product_id'] ?? ''));
            $item = $product?->item;

            if ($product === null || $item === null || $item->archived_at !== null || $item->type === 'kit' || ($item->company_id !== null && $item->company_id !== $store->company_id)) {
                $problem('product_unavailable', $index);

                continue;
            }

            $qty = $this->qty((string) ($input['qty'] ?? ''), $uoms->get($item->base_uom_id));

            if ($qty === null) {
                $problem('qty_invalid', $index, ['product' => $item->name]);

                continue;
            }

            $price = $list === null ? null : $this->prices->priceFor($item, (string) $item->base_uom_id, $list, $local->toDateString(), (string) $qty)?->money;

            if ($price === null) {
                $problem('price_missing', $index, ['product' => $item->name]);

                continue;
            }

            $status = ItemTaxStatus::of($item->tax_category_id, $statuses);

            if ($status['tax_code_id'] === null || ! $status['sellable']) {
                $problem($status['reason'] === ItemTaxStatus::TAX_RATE_NEEDED ? 'tax_rate_missing' : 'tax_code_missing', $index, ['product' => $item->name]);

                continue;
            }

            $available = $stock[$product->id] ?? ['tracked' => false, 'available' => null, 'in_stock' => true];
            $wanted[$item->id] = BigDecimal::of($wanted[$item->id] ?? '0')->plus($qty);

            if (! $available['in_stock'] || ($available['tracked'] && $wanted[$item->id]->isGreaterThan($available['available']))) {
                $problem('out_of_stock', $index, ['product' => $item->name]);
            }

            $gross = BigDecimal::of((string) $price->minor())->multipliedBy($qty)->toScale(0, RoundingMode::HalfUp);
            $lines[] = [
                'key' => (string) $index,
                'product' => $product,
                'item' => $item,
                'uom_id' => (string) $item->base_uom_id,
                'qty' => $qty,
                'unit_price_minor' => (int) (string) $price->minor(),
                'gross_minor' => (int) (string) $gross,
                'tax_code_id' => $status['tax_code_id'],
                'tracked' => $available['tracked'],
            ];
        }

        // ECOM-33: Loyalty's promotions and coupons, on the gross lines.
        $coupon = trim((string) ($cart['coupon'] ?? '')) ?: null;
        $loyalty = class_exists(OnlineLoyalty::class) ? app(OnlineLoyalty::class) : null;
        $promotion = null;

        if ($coupon !== null && ($loyalty === null || ! $loyalty->active() || ! $loyalty->couponUsable($store->company_id, $coupon))) {
            $problem('coupon_invalid');
            $coupon = null;
        }

        if ($loyalty !== null && $lines !== []) {
            $promotion = $loyalty->promotions($store->company_id, $store->branch_id, $partyId, $store->currency, $at, array_map(fn (array $line) => [
                'id' => $line['key'], 'item_id' => $line['item']->id, 'qty' => (string) $line['qty'], 'unit_price_minor' => (string) $line['unit_price_minor'],
            ], $lines), $coupon === null ? [] : [$coupon]);
        }

        $discounts = [];
        $applied = [];

        foreach ($promotion['lines'] ?? [] as $entry) {
            $discounts[$entry['line_id']] = (int) ($discounts[$entry['line_id']] ?? 0) + (int) $entry['discount_minor'];
            $applied[$entry['line_id']][] = ['promotion_id' => $entry['promotion_id'], 'version' => $entry['version'], 'discount_minor' => (string) $entry['discount_minor']];
        }

        if ($coupon !== null && ! collect($promotion['promotions'] ?? [])->contains(fn ($p) => ($p['coupon'] ?? null) !== null)) {
            $problem('coupon_not_applicable');
        }

        $codes = TaxCode::query()->whereKey(array_unique(array_merge(array_column($lines, 'tax_code_id'), array_filter([$store->delivery_tax_code_id]))))->get()->keyBy('id');
        $totals = ['subtotal_minor' => 0, 'discount_minor' => 0, 'tax_minor' => 0, 'delivery_fee_minor' => 0, 'delivery_tax_minor' => 0, 'total_minor' => 0];
        $priced = [];
        $goods = 0;

        foreach ($lines as $no => $line) {
            $discount = min($line['gross_minor'], (int) ($discounts[$line['key']] ?? 0));
            $amount = Money::ofMinor((string) ($line['gross_minor'] - $discount), $store->currency);

            try {
                $result = $this->tax->forLine($amount, [$codes[$line['tax_code_id']]], $inclusive, $local);
            } catch (TaxRateMissing) {
                $problem('tax_rate_missing', (int) $line['key'], ['product' => $line['item']->name]);

                continue;
            }

            $priced[] = [
                'line_no' => $no + 1,
                'key' => $line['key'],
                'product_id' => $line['product']->id,
                'item_id' => $line['item']->id,
                'category_id' => $line['item']->category_id,
                'uom_id' => $line['uom_id'],
                'name' => mb_substr((string) $line['item']->name, 0, 200),
                'qty' => (string) $line['qty'],
                'qty_base' => (string) $line['qty'],
                'unit_price_minor' => $line['unit_price_minor'],
                'gross_minor' => $line['gross_minor'],
                'discount_minor' => $discount,
                'net_minor' => (int) $result->net->minor(),
                'tax_minor' => (int) $result->totalTax()->minor(),
                'total_minor' => (int) $result->gross->minor(),
                'tax_code_id' => $line['tax_code_id'],
                'tax_rate' => isset($result->tax[0]) && $result->tax[0]->rate !== null ? (string) $result->tax[0]->rate : null,
                'tax_inclusive' => $inclusive,
                'promotion' => $applied[$line['key']] ?? null,
                'tracked' => $line['tracked'],
            ];
            $totals['subtotal_minor'] += $line['gross_minor'];
            $totals['discount_minor'] += $discount;
            $totals['tax_minor'] += (int) $result->totalTax()->minor();
            $goods += (int) $result->gross->minor();
        }

        $fulfilment = $this->fulfilment($store, $cart, $goods, $problem);
        $fee = $fulfilment['fee_minor'];
        $feeNet = 0;
        $feeTax = 0;
        $feeTotal = 0;

        if ($fee > 0) {
            if ($store->delivery_tax_code_id !== null && isset($codes[$store->delivery_tax_code_id])) {
                try {
                    $result = $this->tax->forLine(Money::ofMinor((string) $fee, $store->currency), [$codes[$store->delivery_tax_code_id]], $inclusive, $local);
                    [$feeNet, $feeTax, $feeTotal] = [(int) $result->net->minor(), (int) $result->totalTax()->minor(), (int) $result->gross->minor()];
                } catch (TaxRateMissing) {
                    $problem('delivery_tax_rate_missing');
                    [$feeNet, $feeTotal] = [$fee, $fee];
                }
            } else {
                [$feeNet, $feeTotal] = [$fee, $fee];
            }
        }

        $totals['delivery_fee_minor'] = $feeNet;
        $totals['delivery_tax_minor'] = $feeTax;
        $totals['tax_minor'] += $feeTax;
        $totals['total_minor'] = $goods + $feeTotal;

        if ($priced === [] && $problems === []) {
            $problem('cart_empty');
        }

        $minimum = max((int) $store->min_order_minor, (int) ($fulfilment['zone']?->min_order_minor ?? 0));

        if ($priced !== [] && $goods < $minimum) {
            $problem('below_minimum', null, ['amount' => Amounts::show($minimum, $store->currency)]);
        }

        $paid = $this->loyaltyPayments($store, $cart, $partyId, $totals['total_minor'], $loyalty, $problem);

        return [
            'currency' => $store->currency,
            'tax_inclusive' => $inclusive,
            'lines' => $priced,
            'fulfilment' => $fulfilment['fulfilment'],
            'zone_id' => $fulfilment['zone']?->id,
            'pickup_location_id' => $fulfilment['pickup_location_id'],
            'totals' => $totals,
            'coupon' => $coupon,
            'promotions' => array_values($promotion['promotions'] ?? []),
            'loyalty' => $paid,
            'loyalty_paid_minor' => $paid['points_minor'] + $paid['gift_card_minor'],
            'due_minor' => $totals['total_minor'] - $paid['points_minor'] - $paid['gift_card_minor'],
            'problems' => $problems,
        ];
    }

    /**
     * @return array{fulfilment: string, zone: ?Zone, pickup_location_id: ?string, fee_minor: int}
     */
    private function fulfilment(Store $store, array $cart, int $goods, callable $problem): array
    {
        $kind = (string) ($cart['fulfilment'] ?? ($store->delivery_enabled ? 'delivery' : 'pickup'));

        if ($kind === 'pickup') {
            $location = $cart['pickup_location_id'] ?? (($store->pickup_location_ids ?? [])[0] ?? null);

            if (! $store->pickup_enabled || ! in_array($location, $store->pickup_location_ids ?? [], true)) {
                $problem('pickup_unavailable');
            }

            return ['fulfilment' => 'pickup', 'zone' => null, 'pickup_location_id' => is_string($location) ? $location : null, 'fee_minor' => 0];
        }

        if (! $store->delivery_enabled) {
            $problem('delivery_unavailable');

            return ['fulfilment' => 'delivery', 'zone' => null, 'pickup_location_id' => null, 'fee_minor' => 0];
        }

        $zoneId = (string) ($cart['zone_id'] ?? '');
        $zone = Str::isUuid($zoneId) ? Zone::query()->where('store_id', $store->id)->whereKey($zoneId)->where('active', true)->whereNull('archived_at')->first() : null;

        if ($zone === null) {
            $problem($zoneId === '' ? 'zone_required' : 'zone_unknown');

            return ['fulfilment' => 'delivery', 'zone' => null, 'pickup_location_id' => null, 'fee_minor' => 0];
        }

        $free = $zone->free_over_minor !== null && $goods >= (int) $zone->free_over_minor;

        return ['fulfilment' => 'delivery', 'zone' => $zone, 'pickup_location_id' => null, 'fee_minor' => $free ? 0 : (int) $zone->fee_minor];
    }

    /**
     * ECOM-33: points first, then a gift card, in the store's currency.
     *
     * @return array{points: ?string, points_minor: int, gift_card: ?string, gift_card_minor: int}
     */
    private function loyaltyPayments(Store $store, array $cart, ?string $partyId, int $total, ?OnlineLoyalty $loyalty, callable $problem): array
    {
        $paid = ['points' => null, 'points_minor' => 0, 'gift_card' => null, 'gift_card_minor' => 0];
        $points = trim((string) ($cart['points'] ?? ''));
        $card = trim((string) ($cart['gift_card'] ?? ''));

        if ($points !== '' && $points !== '0') {
            $balance = $loyalty !== null && $partyId !== null && $store->methodFor('points') !== null ? $loyalty->points($store->company_id, $partyId) : null;

            if (preg_match('/^[1-9]\d{0,9}$/', $points) !== 1 || $balance === null || $balance['currency'] !== $store->currency) {
                $problem('points_unavailable');
            } elseif (BigInteger::of($points)->isGreaterThan($balance['points'])) {
                $problem('points_insufficient');
            } elseif ((int) $points < $balance['min_redeem_points']) {
                $problem('points_below_minimum', null, ['points' => (string) $balance['min_redeem_points']]);
            } else {
                $value = (int) (string) BigInteger::of($points)->multipliedBy($balance['point_value_minor']);

                if ($value > $total) {
                    $problem('points_exceed_total');
                } else {
                    $paid['points'] = $points;
                    $paid['points_minor'] = $value;
                }
            }
        }

        if ($card !== '') {
            // A gift card is a bearer code: failed lookups are limited per address and per store, so codes cannot be guessed.
            $keys = ['ecom-gift-card|ip|'.(request()?->ip() ?? 'none') => (int) config('ecommerce.shop.gift_card_failures_per_ip_per_hour', 10),
                'ecom-gift-card|store|'.$store->id => (int) config('ecommerce.shop.gift_card_failures_per_store_per_hour', 100)];

            foreach ($keys as $key => $max) {
                if (RateLimiter::tooManyAttempts($key, max(1, $max))) {
                    $seconds = max(1, RateLimiter::availableIn($key));

                    throw new ApiException(429, 'too_many_requests', ApiErrorRenderer::retryMessage($seconds), headers: ['Retry-After' => $seconds]);
                }
            }

            $balance = $loyalty !== null && $store->methodFor('gift_card') !== null ? $loyalty->giftCardBalance($store->company_id, $card, $store->currency) : null;

            if ($balance === null || $balance === '0') {
                foreach (array_keys($keys) as $key) {
                    RateLimiter::hit($key, 3600);
                }

                $problem('gift_card_unusable');
            } else {
                // Only what the order uses is shown (never the card's balance).
                $paid['gift_card'] = $card;
                $paid['gift_card_minor'] = min((int) $balance, $total - $paid['points_minor']);
            }
        }

        return $paid;
    }

    /** A quantity in the unit's precision (whole for `count` units, else up to 3 decimals), positive; null otherwise. */
    private function qty(string $value, ?Uom $uom): ?BigDecimal
    {
        $places = $uom?->kind === 'count' ? 0 : 3;
        $pattern = $places === 0 ? '/^\d{1,6}$/' : '/^\d{1,6}(\.\d{1,3})?$/';

        if (preg_match($pattern, $value) !== 1) {
            return null;
        }

        $qty = BigDecimal::of($value);

        return $qty->isPositive() ? $qty->toScale($places === 0 ? 0 : 3) : null;
    }

    /** @param array<string, mixed> $quote quote() */
    public static function firstProblem(array $quote): ?array
    {
        return $quote['problems'][0] ?? null;
    }

    /** The item a priced line names (for callers that need the model). */
    public static function item(string $id): ?Item
    {
        return Item::query()->find($id);
    }
}
