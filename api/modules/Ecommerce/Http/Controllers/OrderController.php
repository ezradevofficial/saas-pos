<?php

namespace Modules\Ecommerce\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ecommerce\Http\Requests\BackOfficeOrderRequest;
use Modules\Ecommerce\Http\Requests\ListOrdersRequest;
use Modules\Ecommerce\Http\Requests\ListRequest;
use Modules\Ecommerce\Http\Requests\OrderActionRequest;
use Modules\Ecommerce\Http\Requests\QuoteRequest;
use Modules\Ecommerce\Http\Resources\OrderResource;
use Modules\Ecommerce\Http\Resources\Present;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Orders\OrderFlow;
use Modules\Ecommerce\Orders\OrderPayments;
use Modules\Ecommerce\Orders\Placement;
use Modules\Ecommerce\Orders\Pricing;
use Modules\Ecommerce\Orders\QuoteView;
use Modules\Ecommerce\Support\Access;
use Modules\Ecommerce\Support\EcomError;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ECOM-16 to ECOM-22: orders in the back office, at their location
 * (RBAC-04): read with `ecommerce.order.view` (out of scope: 404), moved
 * with `ecommerce.order.manage`, cancelled with `.cancel`, refunded with
 * `.refund`; phone and WhatsApp orders created with `.create` through the
 * same pricing, reservations and numbering as the storefront.
 */
class OrderController
{
    public function __construct(
        private readonly Access $access,
        private readonly OrderFlow $flow,
        private readonly Pricing $pricing,
        private readonly Placement $placement,
        private readonly OrderPayments $payments,
    ) {}

    public function index(ListOrdersRequest $request, ListExport $export): JsonResponse|StreamedResponse
    {
        $query = Order::query();
        $request->applyFilters($query);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return OrderResource::collection($query->paginate($request->perPage())->withQueryString())->response();
    }

    public function show(ListRequest $request, Order $ecomOrder): JsonResponse
    {
        $this->visible($request, $ecomOrder);

        return response()->json(['data' => Present::order($ecomOrder)]);
    }

    /** ECOM-09: a cart priced for staff taking an order (problems listed, never thrown). */
    public function quote(QuoteRequest $request, Store $ecomStore): JsonResponse
    {
        abort_unless($this->access->atLocation($request->user(), 'ecommerce.store.view', $ecomStore->location_id)
            || $this->access->atLocation($request->user(), 'ecommerce.order.create', $ecomStore->location_id), 404);
        $this->access->assertAtLocation($request->user(), 'ecommerce.order.create', $ecomStore->location_id);

        return response()->json(['data' => QuoteView::present($this->pricing->quote($ecomStore, $request->validated(), null, CarbonImmutable::now()))]);
    }

    /** ECOM-22: a phone or WhatsApp order taken by staff (cash on delivery, or an M-Pesa push to the customer). */
    public function store(BackOfficeOrderRequest $request): JsonResponse
    {
        $store = Store::query()->whereKey($request->validated('store_id'))->whereNull('archived_at')->first();
        abort_if($store === null, 404);
        abort_unless($this->access->atLocation($request->user(), 'ecommerce.order.view', $store->location_id)
            || $this->access->atLocation($request->user(), 'ecommerce.order.create', $store->location_id), 404);
        $this->access->assertAtLocation($request->user(), 'ecommerce.order.create', $store->location_id);

        return $this->place($request, $store, (string) $request->validated('channel', 'phone'));
    }

    public function accept(OrderActionRequest $request, Order $ecomOrder): JsonResponse
    {
        return $this->act($request, $ecomOrder, 'ecommerce.order.manage', fn () => $this->flow->accept($ecomOrder, $request->user()));
    }

    public function pick(OrderActionRequest $request, Order $ecomOrder): JsonResponse
    {
        return $this->act($request, $ecomOrder, 'ecommerce.order.manage', fn () => $this->flow->pick($ecomOrder, $request->user()));
    }

    public function ready(OrderActionRequest $request, Order $ecomOrder): JsonResponse
    {
        return $this->act($request, $ecomOrder, 'ecommerce.order.manage', fn () => $this->flow->ready($ecomOrder, $request->user()));
    }

    public function dispatch(OrderActionRequest $request, Order $ecomOrder): JsonResponse
    {
        return $this->act($request, $ecomOrder, 'ecommerce.order.manage', fn () => $this->flow->dispatch($ecomOrder, $request->user()));
    }

    public function deliver(OrderActionRequest $request, Order $ecomOrder): JsonResponse
    {
        return $this->act($request, $ecomOrder, 'ecommerce.order.manage', fn () => $this->flow->deliver($ecomOrder, $request->user(), null,
            $request->filled('collected_minor') ? (int) $request->validated('collected_minor') : null));
    }

    public function cancel(OrderActionRequest $request, Order $ecomOrder): JsonResponse
    {
        return $this->act($request, $ecomOrder, 'ecommerce.order.cancel', fn () => $this->flow->cancel($ecomOrder, $request->user(), (string) $request->validated('reason')));
    }

    public function return(OrderActionRequest $request, Order $ecomOrder): JsonResponse
    {
        return $this->act($request, $ecomOrder, 'ecommerce.order.manage', fn () => $this->flow->return($ecomOrder, $request->user(), (string) $request->validated('reason'),
            (bool) $request->validated('refund_fee', false)));
    }

    /** ECOM-19: pay back a refund-due order (core payout, or by hand with a reference). */
    public function refund(OrderActionRequest $request, Order $ecomOrder): JsonResponse
    {
        return $this->act($request, $ecomOrder, 'ecommerce.order.refund', function () use ($request, $ecomOrder) {
            if (! OrderPayments::refundDue($ecomOrder)) {
                throw EcomError::make('refund_not_due', status: 409);
            }

            $this->payments->refund($ecomOrder, $request->user(), $request->validated('reference'));

            return $ecomOrder->refresh();
        });
    }

    /** @param BackOfficeOrderRequest $request */
    public function place(Request $request, Store $store, string $channel, $conversation = null): JsonResponse
    {
        $key = $request->header('Idempotency-Key');

        if ($key !== null && preg_match('/^[A-Za-z0-9_-]{16,100}$/', $key) !== 1) {
            throw EcomError::make('idempotency_key_invalid', field: 'Idempotency-Key');
        }

        $result = $this->placement->place($store, $request->validated(), $channel, null, $request->user(), $key, app()->getLocale(), $conversation);
        $payment = null;

        if ($result['created'] && in_array($result['order']->payment_kind, ['mpesa', 'card'], true)) {
            try {
                $payment = $this->payments->start($result['order'], $request->validated('pay_phone') ?? $request->validated('contact.phone'));
            } catch (ApiException $e) {
                $payment = ['status' => 'failed', 'redirect_url' => null, 'message' => $e->getMessage()];
            }
        }

        return response()->json(['data' => [...Present::order($result['order']->refresh()), 'payment' => $payment]], $result['created'] ? 201 : 200);
    }

    private function act(Request $request, Order $order, string $permission, callable $fn): JsonResponse
    {
        $this->visible($request, $order);
        $this->access->assertAtLocation($request->user(), $permission, $order->location_id);

        return response()->json(['data' => Present::order($fn()->refresh())]);
    }

    private function visible(Request $request, Order $order): void
    {
        abort_unless($this->access->atLocation($request->user(), 'ecommerce.order.view', $order->location_id), 404);
    }
}
