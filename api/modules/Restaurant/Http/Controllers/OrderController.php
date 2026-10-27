<?php

namespace Modules\Restaurant\Http\Controllers;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Modules\Restaurant\Billing\Bill;
use Modules\Restaurant\Billing\Charges;
use Modules\Restaurant\Http\Requests\BoundRequest;
use Modules\Restaurant\Http\Requests\CancelOrderRequest;
use Modules\Restaurant\Http\Requests\MergeRequest;
use Modules\Restaurant\Http\Requests\SplitRequest;
use Modules\Restaurant\Http\Requests\TransferRequest;
use Modules\Restaurant\Http\Requests\ViewRequest;
use Modules\Restaurant\Http\Requests\VoidLineRequest;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\OrderLine;
use Modules\Restaurant\Models\Table;
use Modules\Restaurant\Orders\OrderActions;
use Modules\Restaurant\Orders\OrderView;
use Modules\Restaurant\Support\Errors;
use Modules\Restaurant\Support\Places;

/** REST-03 to REST-06, REST-11, REST-14, REST-15: orders in the back office. */
class OrderController
{
    public function __construct(
        private readonly Places $places,
        private readonly OrderView $view,
        private readonly OrderActions $actions,
    ) {}

    public function index(ViewRequest $request): JsonResponse
    {
        $query = Order::query()->orderByDesc('opened_at')->orderBy('id');
        $this->places->filterLocations($query, $request->user(), $request->permission());

        foreach (['location_id', 'table_id', 'waiter_id', 'kind'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        $status = $request->input('status', 'open');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($request->filled('search')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower((string) $request->input('search'))).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(tab_name) like ?', [$term])
                ->orWhereIn('table_id', Table::query()->whereRaw('lower(name) like ?', [$term])->select('id')));
        }

        $page = $query->paginate($request->perPage())->withQueryString();

        return response()->json([
            'data' => $this->view->many($page->getCollection(), detail: false),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(BoundRequest $request, Order $restOrder): JsonResponse
    {
        return response()->json(['data' => $this->view->record($restOrder)]);
    }

    public function transfer(TransferRequest $request, Order $restOrder): JsonResponse
    {
        $this->actions->transfer($restOrder, (string) $request->validated('waiter_id'), $request->user());

        return response()->json(['data' => $this->view->record($restOrder->refresh())]);
    }

    public function merge(MergeRequest $request, Order $restOrder): JsonResponse
    {
        $into = Order::query()->whereKey($request->validated('into_order_id'))->first();

        if ($into === null || ! $this->places->at($request->user(), 'restaurant.order.merge', $into->location_id)) {
            throw Errors::make(404, 'order_unknown', field: 'into_order_id');
        }

        return response()->json(['data' => $this->view->record($this->actions->merge($restOrder, $into, $request->user())->refresh())]);
    }

    public function voidLine(VoidLineRequest $request, Order $restOrder, OrderLine $restOrderLine): JsonResponse
    {
        abort_unless($restOrderLine->order_id === $restOrder->id, 404);

        if ($restOrderLine->sent_at !== null) {
            $payable = Bill::line([
                'qty' => (string) $restOrderLine->qty, 'unit_price_minor' => (string) $restOrderLine->unit_price_minor, 'modifiers' => $restOrderLine->modifiers ?? [],
                'tax_rate' => $restOrderLine->tax_rate === null ? null : (string) $restOrderLine->tax_rate, 'tax_inclusive' => (bool) $restOrderLine->tax_inclusive,
            ])['payable'];
            $refusal = $this->actions->voidRefusal($request->user(), $restOrder->location_id, (string) $payable, $restOrder->currency);

            if ($refusal !== null) {
                throw Errors::make(403, $refusal);
            }
        }

        $this->actions->voidLine($restOrder, $restOrderLine, (string) $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->view->record($restOrder->refresh())]);
    }

    public function cancel(CancelOrderRequest $request, Order $restOrder): JsonResponse
    {
        $this->actions->cancel($restOrder, (string) $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->view->record($restOrder->refresh())]);
    }

    /** REST-14: the parts of a split, as the till will ring them. */
    public function split(SplitRequest $request, Order $restOrder, Charges $charges): JsonResponse
    {
        $lines = $this->view->billLines($restOrder->lines()->get());
        $options = array_filter(['parts' => $request->validated('parts'), 'groups' => $request->validated('groups')], fn ($v) => $v !== null);
        $service = $charges->service($restOrder->company_id);
        $tip = (string) $request->validated('tip_minor', '0');

        if ($request->filled('tip_percent') && ! $request->filled('tip_minor')) {
            $tip = Bill::tipFromPercent($lines, (string) $request->validated('tip_percent'));
        }

        try {
            $parts = Bill::split($lines, (string) $request->validated('mode'), $service, $tip, $options);
        } catch (InvalidArgumentException) {
            throw Errors::make(422, 'split_invalid', field: 'mode');
        }

        return response()->json(['data' => ['parts' => $parts]]);
    }
}
