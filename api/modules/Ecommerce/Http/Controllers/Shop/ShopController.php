<?php

namespace Modules\Ecommerce\Http\Controllers\Shop;

use App\Core\Http\ApiException;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ecommerce\Catalogue\ShopCatalogue;
use Modules\Ecommerce\Delivery\ProofPhotos;
use Modules\Ecommerce\Http\Middleware\ResolveShop;
use Modules\Ecommerce\Http\Requests\PlaceOrderRequest;
use Modules\Ecommerce\Http\Requests\QuoteRequest;
use Modules\Ecommerce\Http\Requests\ShopProductsRequest;
use Modules\Ecommerce\Http\Requests\TrackRequest;
use Modules\Ecommerce\Models\CustomerSession;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderEvent;
use Modules\Ecommerce\Models\OrderLine;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Models\Zone;
use Modules\Ecommerce\Notifications\OrderNotices;
use Modules\Ecommerce\Orders\OrderPayments;
use Modules\Ecommerce\Orders\Placement;
use Modules\Ecommerce\Orders\Pricing;
use Modules\Ecommerce\Orders\QuoteView;
use Modules\Ecommerce\Orders\Returns;
use Modules\Ecommerce\Shop\ShopUrls;
use Modules\Ecommerce\Support\EcomError;
use Symfony\Component\HttpFoundation\Response;

/**
 * ECOM-05 to ECOM-21: the public storefront API, in the store's tenant
 * (ResolveShop). Money is computed by the server only (ECOM-12); placing
 * needs an `Idempotency-Key` (ECOM-13). An order is tracked by its number
 * with its tracking token, or by the signed-in customer who placed it;
 * anything else is 404, so a number alone reveals nothing.
 */
class ShopController
{
    public function __construct(
        private readonly ShopCatalogue $catalogue,
        private readonly Pricing $pricing,
        private readonly Placement $placement,
        private readonly OrderPayments $payments,
        private readonly ShopUrls $urls,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $store = self::store($request);
        $pickups = Location::query()->whereKey($store->pickup_location_ids ?? [])->orderBy('name')->get(['id', 'name']);
        $kinds = collect($store->payment_methods ?? [])->filter(fn (array $entry) => PaymentMethod::query()->whereKey($entry['payment_method_id'])->where('active', true)->exists())
            ->pluck('kind')->values();

        return response()->json(['data' => [
            'handle' => $store->handle,
            'name' => $store->name,
            'currency' => $store->currency,
            'contact_phone' => $store->contact_phone,
            'contact_email' => $store->contact_email,
            'whatsapp' => $store->whatsapp_number === null ? null : ShopUrls::whatsapp($store->name, $store->whatsapp_number),
            'share' => ['url' => $this->urls->store($store), 'whatsapp' => ShopUrls::whatsapp($store->name.' '.$this->urls->store($store))],
            'min_order_minor' => (string) $store->min_order_minor,
            'delivery_enabled' => $store->delivery_enabled,
            'pickup_enabled' => $store->pickup_enabled,
            'pickup_locations' => $pickups->map(fn (Location $l) => ['id' => $l->id, 'name' => $l->name])->values(),
            'zones' => Zone::query()->where('store_id', $store->id)->where('active', true)->whereNull('archived_at')->orderBy('position')->orderBy('name')->get()
                ->map(fn (Zone $z) => ['id' => $z->id, 'name' => $z->name, 'areas' => $z->areas ?? [], 'fee_minor' => (string) $z->fee_minor,
                    'free_over_minor' => $z->free_over_minor === null ? null : (string) $z->free_over_minor, 'eta' => $z->eta])->values(),
            'payment_kinds' => $kinds,
            'categories' => $this->catalogue->categories($store),
            // ECOM-44: checkout offers order messages on WhatsApp only when the channel is configured.
            'whatsapp_notifications' => app(OrderNotices::class)->whatsappAvailable(),
            'signed_in' => self::session($request) !== null,
        ]]);
    }

    public function products(ShopProductsRequest $request): JsonResponse
    {
        $store = self::store($request);
        $search = trim((string) $request->validated('search', ''));
        $query = $this->catalogue->published($store)->with('item.images')
            ->when($request->filled('category'), fn ($q) => $q->whereHas('item', fn ($i) => $i->where('category_id', $request->validated('category'))))
            ->when($search !== '', fn ($q) => $q->whereHas('item', fn ($i) => $i->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%')))
            ->when($request->boolean('featured'), fn ($q) => $q->where('featured', true))
            ->orderByDesc('featured')->orderBy('position')->orderBy('slug');
        $page = $query->paginate((int) $request->validated('per_page', 24));

        return response()->json(['data' => $this->catalogue->present($store, $page->getCollection()),
            'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function product(Request $request, string $store, string $slug): JsonResponse
    {
        $shop = self::store($request);
        $product = $this->catalogue->published($shop)->where('slug', $slug)->with('item.images')->first();
        abort_if($product === null, 404);

        return response()->json(['data' => $this->catalogue->present($shop, collect([$product]), true)[0]]);
    }

    public function quote(QuoteRequest $request): JsonResponse
    {
        $store = self::store($request);

        return response()->json(['data' => QuoteView::present($this->pricing->quote($store, $request->validated(), self::session($request)?->customer?->party_id, CarbonImmutable::now()))]);
    }

    /** ECOM-11 to ECOM-15: place the order; an online payment starts at once (M-Pesa push or card checkout). */
    public function place(PlaceOrderRequest $request): JsonResponse
    {
        $store = self::store($request);
        $key = (string) $request->header('Idempotency-Key', '');

        if (preg_match('/^[A-Za-z0-9_-]{16,100}$/', $key) !== 1) {
            throw EcomError::make('idempotency_key_required', field: 'Idempotency-Key', status: 428);
        }

        $result = $this->placement->place($store, $request->validated(), 'web', self::session($request)?->customer, null, $key, app()->getLocale());
        $order = $result['order'];
        $payment = null;

        if ($result['created'] && in_array($order->payment_kind, ['mpesa', 'card'], true)) {
            try {
                $payment = $this->payments->start($order, $request->validated('pay_phone') ?? $request->validated('contact.phone'));
            } catch (ApiException $e) {
                $payment = ['status' => 'failed', 'redirect_url' => null, 'message' => $e->getMessage()];
            }
        }

        // The tracking token is given once, to the placing request (a replay gets the order, not the token again).
        return response()->json(['data' => [...$this->tracked($order->refresh()), 'tracking_token' => $result['token'], 'payment' => $payment]], $result['created'] ? 201 : 200);
    }

    public function track(TrackRequest $request, string $store, string $number): JsonResponse
    {
        return response()->json(['data' => $this->tracked($this->order($request, $number))]);
    }

    /** ECOM-15: try the online payment again (until the reservation expires). */
    public function pay(TrackRequest $request, string $store, string $number): JsonResponse
    {
        $order = $this->order($request, $number);
        $payment = $this->payments->start($order, $request->validated('phone'));

        return response()->json(['data' => [...$this->tracked($order->refresh()), 'payment' => $payment]]);
    }

    /** ECOM-15: the waiting page asks where the payment stands. */
    public function check(TrackRequest $request, string $store, string $number): JsonResponse
    {
        $order = $this->order($request, $number);
        $this->payments->check($order);

        return response()->json(['data' => $this->tracked($order->refresh())]);
    }

    /**
     * ECOM-41: the photo that proved the delivery, for the customer of the
     * order only (its tracking token or their session), once delivered.
     */
    public function proofPhoto(TrackRequest $request, ProofPhotos $photos, string $store, string $number): Response
    {
        $order = $this->order($request, $number);
        $delivery = Delivery::query()->where('order_id', $order->id)->first();
        abort_unless($delivery !== null && $delivery->status === Delivery::DELIVERED && $delivery->proof_photo_path !== null, 404);

        return $photos->response($delivery);
    }

    private function order(Request $request, string $number): Order
    {
        $store = self::store($request);
        $order = Order::query()->where('store_id', $store->id)->where('number', $number)->first();
        $token = (string) $request->query('t', $request->input('t', ''));
        $session = self::session($request);
        $mine = $order !== null && $session !== null && $order->customer_id === $session->customer_id;

        abort_unless($order !== null && ($mine || ($token !== '' && hash_equals($order->tracking_token_hash, hash('sha256', $token)))), 404);

        return $order;
    }

    /** ECOM-21, ECOM-29: what the customer may see of their order. */
    private function tracked(Order $order): array
    {
        $delivery = Delivery::query()->where('order_id', $order->id)->first();
        $rider = $delivery?->rider_id === null ? null : Rider::query()->find($delivery->rider_id);

        return [
            'number' => $order->number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment_kind' => $order->payment_kind,
            'fulfilment' => $order->fulfilment,
            'currency' => $order->currency,
            'totals' => [
                'subtotal_minor' => (string) $order->subtotal_minor, 'discount_minor' => (string) $order->discount_minor, 'tax_minor' => (string) $order->tax_minor,
                // The fee as the customer pays it (with its tax): what the order totals above its lines.
                'delivery_minor' => (string) ($order->total_minor - (int) OrderLine::query()->where('order_id', $order->id)->sum('total_minor')),
                'total_minor' => (string) $order->total_minor, 'loyalty_paid_minor' => (string) $order->loyalty_paid_minor, 'due_minor' => (string) $order->due_minor,
            ],
            'lines' => OrderLine::query()->where('order_id', $order->id)->orderBy('line_no')->get()->map(fn (OrderLine $l) => [
                'name' => $l->name, 'qty' => (string) $l->qty, 'unit_price_minor' => (string) $l->unit_price_minor, 'discount_minor' => (string) $l->discount_minor, 'total_minor' => (string) $l->total_minor,
            ])->values(),
            'history' => OrderEvent::query()->where('order_id', $order->id)->orderBy('at')->orderBy('id')->get()->map(fn (OrderEvent $e) => ['status' => $e->to, 'payment_status' => $e->payment_status, 'at' => $e->at->toIso8601String()])->values(),
            'rider' => $rider === null ? null : ['first_name' => trim(explode(' ', $rider->name)[0] ?? '')],
            'last_location_at' => $delivery?->located_at?->toIso8601String(),
            // ECOM-40, review M2: where the rider is, only while this order is the rider's one stop on the way
            // (no other delivery of theirs picked up), so a customer never sees the rider at another's door.
            'rider_location' => $this->riderOnlyHere($order, $delivery)
                ? ['latitude' => (string) $delivery->last_latitude, 'longitude' => (string) $delivery->last_longitude, 'at' => $delivery->located_at->toIso8601String()] : null,
            // ECOM-41: the photo is fetched from …/proof-photo with the same token.
            'proof_photo' => $delivery?->status === Delivery::DELIVERED && $delivery->proof_photo_path !== null,
            'returns' => Returns::forCustomer($order),
            'whatsapp_opt_in' => (bool) $order->whatsapp_opt_in,
            'placed_at' => $order->placed_at->toIso8601String(),
            'reserved_until' => $order->reserved_until?->toIso8601String(),
        ];
    }

    private function riderOnlyHere(Order $order, ?Delivery $delivery): bool
    {
        return $order->status === Order::OUT_FOR_DELIVERY && $delivery?->status === Delivery::PICKED_UP && $delivery->rider_id !== null
            && $delivery->located_at !== null && $delivery->last_latitude !== null
            && Delivery::query()->where('rider_id', $delivery->rider_id)->where('status', Delivery::PICKED_UP)->whereKeyNot($delivery->id)->doesntExist();
    }

    public static function store(Request $request): Store
    {
        return $request->attributes->get(ResolveShop::STORE);
    }

    public static function session(Request $request): ?CustomerSession
    {
        return $request->attributes->get(ResolveShop::SESSION);
    }
}
