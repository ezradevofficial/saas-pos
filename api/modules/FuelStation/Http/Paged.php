<?php

namespace Modules\FuelStation\Http;

use App\Core\Tenancy\Http\Requests\ListRequest;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The module's list envelope: `{data, meta: {current_page, last_page, per_page, total}}`, newest first unless the caller ordered the query. */
final class Paged
{
    public static function respond(Builder $query, Request $request, Closure $map): JsonResponse
    {
        $perPage = max(1, min(ListRequest::MAX_PER_PAGE, (int) $request->query('per_page', ListRequest::PER_PAGE)));
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => $page->getCollection()->map($map)->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    /** All rows of a short list (stations, tanks, pumps): `{data}`. */
    public static function all(Builder $query, Closure $map): JsonResponse
    {
        return response()->json(['data' => $query->get()->map($map)->values()->all()]);
    }
}
