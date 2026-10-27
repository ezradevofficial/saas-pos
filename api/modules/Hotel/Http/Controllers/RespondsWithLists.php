<?php

namespace Modules\Hotel\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The list contract of Hotel's list endpoints (EXP-01, ADR 008): sorted, paged, or an export of the same rows. */
trait RespondsWithLists
{
    /**
     * @param  class-string  $resource
     * @param  object  $request  a request using SortsAndExports and offering perPage()
     */
    protected function listed(object $request, Builder $query, string $resource, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return $resource::collection($query->paginate($request->perPage())->withQueryString());
    }
}
