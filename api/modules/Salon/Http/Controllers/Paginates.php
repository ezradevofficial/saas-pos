<?php

namespace Modules\Salon\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Modules\Salon\Http\Requests\ViewRequest;

/** A paginated list in the shape every Salon list has: `data`, `links` and `meta`. */
trait Paginates
{
    protected function paged(Builder $query, ViewRequest $request, callable $map): JsonResponse
    {
        $page = $query->paginate($request->perPage())->withQueryString();

        return response()->json([
            'data' => $map($page->getCollection()),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }
}
