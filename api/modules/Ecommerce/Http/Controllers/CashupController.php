<?php

namespace Modules\Ecommerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Ecommerce\Delivery\Cashups;
use Modules\Ecommerce\Http\Requests\CashupRequest;
use Modules\Ecommerce\Http\Requests\ListRequest;
use Modules\Ecommerce\Http\Resources\Present;
use Modules\Ecommerce\Models\Cashup;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Support\Access;

/**
 * ECOM-30: rider cash-ups of a company: read with `ecommerce.cashup.view`
 * (the pending collections and the closed cash-ups), closed with
 * `ecommerce.cashup.manage`.
 */
class CashupController
{
    public function __construct(private readonly Access $access, private readonly Cashups $cashups) {}

    public function index(ListRequest $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'ecommerce.cashup.view'), 403);
        $query = $this->access->byCompany(Cashup::query(), $request->user(), 'ecommerce.cashup.view')
            ->when($request->filled('rider'), fn ($q) => $q->where('rider_id', $request->validated('rider')))
            ->orderByDesc('closed_at');
        $page = $query->paginate($request->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (Cashup $c) => Present::cashup($c))->values(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function pending(ListRequest $request): JsonResponse
    {
        abort_unless($this->access->can($request->user(), 'ecommerce.cashup.view'), 403);
        $riders = $this->access->byCompany(Rider::query(), $request->user(), 'ecommerce.cashup.view')->get()->keyBy('id');
        $rows = $this->cashups->pending($riders->keys()->all());

        return response()->json(['data' => array_map(fn (array $row) => [...$row, 'rider' => ['id' => $row['rider_id'], 'name' => $riders[$row['rider_id']]->name]], $rows)]);
    }

    public function store(CashupRequest $request): JsonResponse
    {
        $rider = Rider::query()->findOrFail($request->validated('rider_id'));
        abort_unless($this->access->atCompany($request->user(), 'ecommerce.cashup.view', $rider->company_id), 404);
        $this->access->assertAtCompany($request->user(), 'ecommerce.cashup.manage', $rider->company_id);
        $cashup = $this->cashups->close($rider, strtoupper((string) $request->validated('currency')), (int) $request->validated('counted_minor'), $request->validated('note'), $request->user());

        return response()->json(['data' => Present::cashup($cashup)], 201);
    }
}
