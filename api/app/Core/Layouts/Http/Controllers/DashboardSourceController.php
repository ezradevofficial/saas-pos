<?php

namespace App\Core\Layouts\Http\Controllers;

use App\Core\Layouts\Dashboards\DashboardSource;
use App\Core\Layouts\Dashboards\DashboardSources;
use App\Core\Layouts\Http\Requests\DashboardSourceRequest;
use App\Core\Layouts\Http\Requests\ListDashboardSourcesRequest;
use Illuminate\Http\JsonResponse;

/**
 * LAY-01: dashboard data sources. `index` lists those the user may read,
 * with the widget types each feeds; `show` answers one widget's data,
 * scoped to what the user reaches (RBAC-04, RBAC-05).
 */
class DashboardSourceController
{
    public function index(ListDashboardSourcesRequest $request, DashboardSources $sources): JsonResponse
    {
        return new JsonResponse(['data' => array_map(fn (DashboardSource $source) => [
            'key' => $source->key(),
            'label' => __($source->label()),
            'module' => $source->module(),
            'widgets' => $source->widgets(),
            'params' => array_keys(array_filter($source->rules(), fn (string $field) => ! str_contains($field, '*'), ARRAY_FILTER_USE_KEY)),
        ], $sources->availableTo($request->user()))]);
    }

    public function show(DashboardSourceRequest $request): JsonResponse
    {
        return new JsonResponse(['data' => $request->source()->data($request->user(), $request->validated())]);
    }
}
