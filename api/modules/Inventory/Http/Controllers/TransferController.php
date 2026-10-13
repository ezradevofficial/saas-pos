<?php

namespace Modules\Inventory\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Inventory\Documents\Transfers;
use Modules\Inventory\Http\Lists\TransferList;
use Modules\Inventory\Http\Requests\DocumentActionRequest;
use Modules\Inventory\Http\Requests\ListTransfersRequest;
use Modules\Inventory\Http\Requests\ShowDocumentRequest;
use Modules\Inventory\Http\Requests\StoreTransferRequest;
use Modules\Inventory\Http\Resources\TransferResource;
use Modules\Inventory\Models\Transfer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** INV-08: transfers in the back office, from or to the reader's locations (RBAC-04). */
class TransferController
{
    public const DETAIL = [...TransferList::RELATIONS, 'lines.item', 'lines.uom', 'lines.batch'];

    public function __construct(private readonly Transfers $transfers) {}

    public function index(ListTransfersRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Transfer::query()->with(TransferList::RELATIONS)->withCount('lines');
        $request->applyFilters($query, ['from_location_id', 'to_location_id'], 'created_at');
        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('number', 'ilike', $like)->orWhere('note', 'ilike', $like));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return TransferResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowDocumentRequest $request, Transfer $inventoryTransfer): TransferResource
    {
        return TransferResource::make($inventoryTransfer->load(self::DETAIL)->loadCount('lines'))->detail();
    }

    public function store(StoreTransferRequest $request): JsonResponse
    {
        $transfer = $this->transfers->create($request->validated(), $request->user());

        return TransferResource::make($transfer->load(self::DETAIL)->loadCount('lines'))->detail()->response()->setStatusCode(201);
    }

    public function dispatch(DocumentActionRequest $request, Transfer $inventoryTransfer): TransferResource
    {
        return $this->detail($this->transfers->dispatch($inventoryTransfer, $request->user()));
    }

    public function receive(DocumentActionRequest $request, Transfer $inventoryTransfer): TransferResource
    {
        return $this->detail($this->transfers->receive($inventoryTransfer, $request->user(), $request->validated('lines'), $request->validated('loss_reason')));
    }

    public function cancel(DocumentActionRequest $request, Transfer $inventoryTransfer): TransferResource
    {
        return $this->detail($this->transfers->cancel($inventoryTransfer, $request->user(), (string) $request->validated('reason')));
    }

    private function detail(Transfer $transfer): TransferResource
    {
        return TransferResource::make($transfer->load(self::DETAIL)->loadCount('lines'))->detail();
    }
}
