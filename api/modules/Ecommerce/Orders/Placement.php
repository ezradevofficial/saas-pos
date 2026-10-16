<?php

namespace Modules\Ecommerce\Orders;

use App\Core\Currency\Converter;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Ecommerce\Customers\Customers;
use Modules\Ecommerce\Events\OrderEvent;
use Modules\Ecommerce\Models\Address;
use Modules\Ecommerce\Models\Conversation;
use Modules\Ecommerce\Models\Customer;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderEvent as History;
use Modules\Ecommerce\Models\OrderLine;
use Modules\Ecommerce\Models\Payment;
use Modules\Ecommerce\Models\Product;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Notifications\OrderNotices;
use Modules\Ecommerce\Shop\ShopUrls;
use Modules\Ecommerce\Support\EcomError;
use Modules\Ecommerce\Support\Tx;
use Modules\Loyalty\Contracts\OnlineLoyalty;

/**
 * ECOM-11 to ECOM-17: placing an order, from the storefront (`web`), or by
 * staff for a phone or WhatsApp customer.
 *
 * Idempotent (ECOM-13): the idempotency key is kept per store with a hash
 * of the request; the same key and body answer the order stored, another
 * body is refused (`idempotency_mismatch`). Under an advisory lock on the
 * key, then the locks of the location and items (Reservations), the cart
 * is priced again (Pricing: server-side money only); a total other than
 * the one the customer saw is refused (`total_changed`, with the new
 * quote) and nothing is stored (ECOM-12). In one transaction: the customer
 * and its party, the number (NUM-01), the order and its lines with base
 * amounts at the company's rate (CUR-04), the reservations, the Loyalty
 * redemptions, the payment rows and the first history step. The online
 * payment starts after commit (OrderPayments::start).
 */
final class Placement
{
    public const NUMBER_TYPE = 'ecommerce.order';

    public function __construct(
        private readonly Pricing $pricing,
        private readonly Reservations $reservations,
        private readonly Customers $customers,
        private readonly Numbering $numbering,
        private readonly Converter $converter,
        private readonly OrderNotices $notices,
        private readonly ShopUrls $urls,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated: lines, fulfilment, zone_id, pickup_location_id, coupon, points, gift_card, payment, contact{name, phone, email}, address{line, area, notes}, notes, expected_total_minor
     * @return array{order: Order, created: bool, token: ?string}
     */
    public function place(Store $store, array $input, string $channel, ?Customer $signedIn, ?User $by, ?string $idempotencyKey, string $locale, ?Conversation $conversation = null): array
    {
        $company = Company::query()->findOrFail($store->company_id);
        $bodyHash = hash('sha256', json_encode(self::canonical($input)));

        if ($idempotencyKey !== null && ($existing = $this->replay($store, $idempotencyKey, $bodyHash)) !== null) {
            return ['order' => $existing, 'created' => false, 'token' => null];
        }

        // ECOM-25: a chat's order is the chat's: its phone is the conversation's, whatever was typed.
        $phone = $conversation !== null ? $conversation->phone : Customers::phone($input['contact']['phone'] ?? null, $company);
        $email = Customers::email($input['contact']['email'] ?? null);

        if ($phone === null) {
            throw EcomError::make('phone_invalid', field: 'contact.phone');
        }

        $result = Tx::run(function () use ($store, $company, $input, $channel, $signedIn, $by, $idempotencyKey, $bodyHash, $phone, $email, $locale, $conversation) {
            if ($idempotencyKey !== null) {
                Tx::lock("ecom:idem:{$store->id}:{$idempotencyKey}");

                if (($existing = $this->replay($store, $idempotencyKey, $bodyHash)) !== null) {
                    return ['order' => $existing, 'created' => false, 'token' => null];
                }
            }

            // ECOM-25: one order per chat: the conversation is locked and its status read again.
            if ($conversation !== null) {
                $conversation = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();

                if ($conversation->status === 'converted' && $conversation->order_id !== null) {
                    throw EcomError::make('conversation_converted', status: 409);
                }
            }

            $kind = (string) ($input['payment'] ?? '');
            $now = CarbonImmutable::now();
            // ECOM-08, ECOM-33: only a signed-in customer's order names a customer and its party (for
            // points, member promotions and store credit); a guest order names neither, and its
            // contact never reaches an existing customer.
            $customer = $signedIn;
            $typedName = mb_substr(trim((string) ($input['contact']['name'] ?? '')), 0, 160);

            // A customer created at sign-in is named by its contact until they give their name.
            if ($customer !== null && $typedName !== '' && in_array($customer->name, array_filter([$customer->phone, $customer->email]), true)) {
                $customer->forceFill(['name' => $typedName])->save();
            }

            $partyId = $customer === null ? null : $this->customers->party($customer, $company);

            // ECOM-14: lock the location and items before availability is read.
            $itemIds = Product::query()->where('store_id', $store->id)->whereKey(array_values(array_filter(array_column($input['lines'], 'product_id'), Str::isUuid(...))))->pluck('item_id')->all();
            $this->reservations->lock($store->location_id, $itemIds);

            $quote = $this->pricing->quote($store, $input, $partyId, $now);

            if (($problem = Pricing::firstProblem($quote)) !== null) {
                throw new ApiException(422, $problem['code'], $problem['message'], [], ['quote' => QuoteView::present($quote)]);
            }

            if ((string) $quote['totals']['total_minor'] !== (string) ($input['expected_total_minor'] ?? '')) {
                throw EcomError::make('total_changed', status: 409, extra: ['quote' => QuoteView::present($quote)]);
            }

            $due = (int) $quote['due_minor'];

            if ($due > 0 && (! in_array($kind, ['mpesa', 'card', 'cod'], true) || $store->methodFor($kind) === null)) {
                throw EcomError::make('payment_unavailable', field: 'payment');
            }

            $kind = $due === 0 ? 'loyalty' : $kind;
            $token = Str::random(40);
            $deliveryAddress = $quote['fulfilment'] === 'delivery' ? array_filter([
                'line' => mb_substr(trim((string) ($input['address']['line'] ?? '')), 0, 300),
                'area' => mb_substr(trim((string) ($input['address']['area'] ?? '')), 0, 120) ?: null,
                'notes' => mb_substr(trim((string) ($input['address']['notes'] ?? '')), 0, 300) ?: null,
            ]) : null;

            if ($quote['fulfilment'] === 'delivery' && ($deliveryAddress['line'] ?? '') === '') {
                throw EcomError::make('address_required', field: 'address.line');
            }

            [$fx, $baseTotal] = $this->base($company, $store->currency, (int) $quote['totals']['total_minor'], $now);
            $online = in_array($kind, ['mpesa', 'card'], true);

            $order = Order::create([
                'store_id' => $store->id,
                'company_id' => $store->company_id,
                'branch_id' => $store->branch_id,
                'location_id' => $store->location_id,
                'number' => $this->numbering->next(self::NUMBER_TYPE, new NumberContext($company, Branch::query()->find($store->branch_id), at: $now))->number,
                'channel' => $channel,
                'customer_id' => $customer?->id,
                'party_id' => $partyId,
                'contact_name' => mb_substr(trim((string) ($input['contact']['name'] ?? '')), 0, 160) ?: ($customer?->name ?? $phone),
                'contact_phone' => $phone,
                'contact_email' => $email,
                'locale' => in_array($locale, ['en', 'fr'], true) ? $locale : 'en',
                'fulfilment' => $quote['fulfilment'],
                'zone_id' => $quote['zone_id'],
                'pickup_location_id' => $quote['pickup_location_id'],
                'address' => $deliveryAddress,
                'status' => Order::PLACED,
                'payment_status' => match (true) {
                    $due === 0 => 'paid',
                    $kind === 'cod' => 'cod',
                    default => 'pending',
                },
                'payment_kind' => $kind,
                'currency' => $store->currency,
                'subtotal_minor' => $quote['totals']['subtotal_minor'],
                'discount_minor' => $quote['totals']['discount_minor'],
                'tax_minor' => $quote['totals']['tax_minor'],
                'delivery_fee_minor' => $quote['totals']['delivery_fee_minor'],
                'delivery_tax_minor' => $quote['totals']['delivery_tax_minor'],
                'total_minor' => $quote['totals']['total_minor'],
                'loyalty_paid_minor' => $quote['loyalty_paid_minor'],
                'due_minor' => $due,
                'base_currency' => $company->base_currency,
                'fx' => $fx,
                'base_total_minor' => $baseTotal,
                'coupon' => $quote['coupon'],
                'promotions' => $quote['promotions'],
                'idempotency_key' => $idempotencyKey,
                'body_hash' => $bodyHash,
                'tracking_token_hash' => hash('sha256', $token),
                'tracking_token_secret' => $token,
                'reserved_until' => $online ? $now->addMinutes((int) $store->reservation_minutes) : null,
                'placed_at' => $now,
                'paid_at' => $due === 0 ? $now : null,
                'conversation_id' => $conversation?->id,
                'created_by' => $by?->id,
                'notes' => mb_substr(trim((string) ($input['notes'] ?? '')), 0, 1000) ?: null,
            ]);

            foreach ($quote['lines'] as $line) {
                OrderLine::create([
                    'order_id' => $order->id,
                    ...array_intersect_key($line, array_flip(['line_no', 'product_id', 'item_id', 'uom_id', 'name', 'qty', 'qty_base', 'unit_price_minor', 'gross_minor',
                        'discount_minor', 'net_minor', 'tax_minor', 'total_minor', 'tax_code_id', 'tax_rate', 'tax_inclusive', 'promotion'])),
                ]);
            }

            $this->reservations->reserve($order, $quote['lines'], $online ? $order->reserved_until : null);
            $this->loyalty($store, $order, $quote, $partyId);

            if ($due > 0) {
                Payment::create(['order_id' => $order->id, 'kind' => $kind, 'payment_method_id' => $store->methodFor($kind), 'currency' => $store->currency,
                    'amount_minor' => $due, 'status' => 'pending']);
            }

            if ($conversation !== null) {
                $conversation->forceFill(['status' => 'converted', 'order_id' => $order->id])->save();
            }

            if ($signedIn !== null && $deliveryAddress !== null && ! Address::query()->where('customer_id', $signedIn->id)->where('line', $deliveryAddress['line'])->exists()) {
                Address::create(['customer_id' => $signedIn->id, 'zone_id' => $quote['zone_id'], 'area' => $deliveryAddress['area'] ?? null, 'line' => $deliveryAddress['line'], 'notes' => $deliveryAddress['notes'] ?? null]);
            }

            History::create(['order_id' => $order->id, 'from' => null, 'to' => Order::PLACED, 'payment_status' => $order->payment_status, 'user_id' => $by?->id, 'at' => $now]);
            OrderEvent::dispatch($order->tenant_id, OrderEvent::PLACED, $order->id, $order->company_id, $order->number, $order->currency, (string) $order->total_minor);

            return ['order' => $order, 'created' => true, 'token' => $token];
        });

        if ($result['created']) {
            $this->notices->send(OrderNotices::PLACED, $result['order'], $store, ['tracking_url' => $this->urls->tracking($store, $result['order'], (string) $result['token'])]);
        }

        return $result;
    }

    /** ECOM-33: points and the gift card are redeemed now, in the placing transaction. */
    private function loyalty(Store $store, Order $order, array $quote, ?string $partyId): void
    {
        $paid = $quote['loyalty'];

        if ($paid['points_minor'] === 0 && $paid['gift_card_minor'] === 0 && $quote['coupon'] === null) {
            return;
        }

        $loyalty = app(OnlineLoyalty::class);

        if ($quote['coupon'] !== null) {
            $loyalty->useCoupons($store->company_id, [$quote['coupon']]);
        }

        if ($paid['points_minor'] > 0 && $partyId !== null) {
            $value = $loyalty->redeemPoints($store->company_id, $partyId, (string) $paid['points'], $order->id, $store->currency);
            Payment::create(['order_id' => $order->id, 'kind' => 'points', 'payment_method_id' => $store->methodFor('points'), 'currency' => $store->currency,
                'amount_minor' => (int) $value, 'status' => 'succeeded', 'detail' => ['points' => (string) $paid['points']]]);
        }

        if ($paid['gift_card_minor'] > 0) {
            $card = $loyalty->redeemGiftCard($store->company_id, (string) $paid['gift_card'], (string) $paid['gift_card_minor'], $store->currency, $order->id);
            Payment::create(['order_id' => $order->id, 'kind' => 'gift_card', 'payment_method_id' => $store->methodFor('gift_card'), 'currency' => $store->currency,
                'amount_minor' => $paid['gift_card_minor'], 'status' => 'succeeded', 'detail' => ['gift_card_id' => $card]]);
        }
    }

    /** @return array{0: FxSnapshot, 1: int} CUR-04: the company's rate at placement and the base total */
    private function base(Company $company, string $currency, int $total, CarbonImmutable $at): array
    {
        if ($currency === $company->base_currency) {
            return [FxSnapshot::identity($currency), $total];
        }

        try {
            $converted = $this->converter->toBase(Money::ofMinor((string) $total, $currency), $company, $at);
        } catch (RateUnavailable) {
            throw EcomError::make('rate_unavailable', ['pair' => "{$currency}/{$company->base_currency}"]);
        }

        return [$converted['snapshot'], (int) $converted['base']->minor()];
    }

    private function replay(Store $store, string $key, string $hash): ?Order
    {
        $order = Order::query()->where('store_id', $store->id)->where('idempotency_key', $key)->first();

        if ($order !== null && ! hash_equals((string) $order->body_hash, $hash)) {
            throw EcomError::make('idempotency_mismatch', status: 409);
        }

        return $order;
    }

    /** The request as a stable structure (keys sorted), for the idempotency hash. */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_scalar($value) ? (string) $value : $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
