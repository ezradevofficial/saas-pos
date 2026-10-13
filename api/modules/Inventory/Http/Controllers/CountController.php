<?php

namespace Modules\Inventory\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Inventory\Documents\Counts;
use Modules\Inventory\Http\Lists\CountList;
use Modules\Inventory\Http\Requests\DocumentActionRequest;
use Modules\Inventory\Http\Requests\ListCountsRequest;
use Modules\Inventory\Http\Requests\ShowDocumentRequest;
use Modules\Inventory\Http\Requests\StoreCountRequest;
use Modules\Inventory\Http\Resources\CountResource;
use Modules\Inventory\Models\StockCount;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** INV-09: stock counts in the back office, at the reader's locations (RBAC-04). */
class CountController
{
    public const DETAIL = [...CountList::RELATIONS, 'lines.item.baseUom', 'lines.batch'];

    public function __construct(private readonly Counts $counts) {}

    public function index(ListCountsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = StockCount::query()->with(CountList::RELATIONS)->withCount('lines');
        $request->applyFilters($query, dateColumn: 'created_at');
        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('number', 'ilike', $like)->orWhere('note', 'ilike', $like));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return CountResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowDocumentRequest $request, StockCount $inventoryCount): CountResource
    {
        return $this->detail($inventoryCount);
    }

    public function store(StoreCountRequest $request): JsonResponse
    {
        return $this->detail($this->counts->start($request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    public function lines(DocumentActionRequest $request, StockCount $inventoryCount): CountResource
    {
        return $this->detail($this->counts->enter($inventoryCount, $request->validated('lines')));
    }

    public function submit(DocumentActionRequest $request, StockCount $inventoryCount): CountResource
    {
        return $this->detail($this->counts->submit($inventoryCount, $request->user()));
    }

    public function cancel(DocumentActionRequest $request, StockCount $inventoryCount): CountResource
    {
        return $this->detail($this->counts->cancel($inventoryCount, $request->user(), (string) $request->validated('reason')));
    }

    private function detail(StockCount $count): CountResource
    {
        return CountResource::make($count->refresh()->load(self::DETAIL)->loadCount('lines'))->detail();
    }
}
