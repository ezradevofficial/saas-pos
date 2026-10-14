<?php

namespace Modules\Stores\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Stores\Http\Lists\ReturnList;
use Modules\Stores\Http\Requests\ListReturnsRequest;
use Modules\Stores\Http\Requests\ShowDocumentRequest;
use Modules\Stores\Http\Resources\ReturnResource;
use Modules\Stores\Models\StoreReturn;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** STR-06: returns to store at the reader's stores (RBAC-04). */
class ReturnController
{
    public const DETAIL = [...ReturnList::RELATIONS, 'lines.item', 'lines.uom'];

    public function index(ListReturnsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = StoreReturn::query()->with(ReturnList::RELATIONS)->withCount('lines');
        $request->applyFilters($query, dateColumn: 'returned_at');
        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('number', 'ilike', $like)->orWhere('note', 'ilike', $like));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return ReturnResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowDocumentRequest $request, StoreReturn $storesReturn): ReturnResource
    {
        return ReturnResource::make($storesReturn->load(self::DETAIL)->loadCount('lines'))->detail();
    }
}
