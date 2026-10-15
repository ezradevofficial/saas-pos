<?php

namespace Modules\FixedAssets\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\FixedAssets\Depreciation\Runs;
use Modules\FixedAssets\Http\Requests\ListRunsRequest;
use Modules\FixedAssets\Http\Requests\PreviewRunRequest;
use Modules\FixedAssets\Http\Requests\ReverseRunRequest;
use Modules\FixedAssets\Http\Requests\ShowRunRequest;
use Modules\FixedAssets\Http\Requests\StoreRunRequest;
use Modules\FixedAssets\Http\Resources\RecordResources;
use Modules\FixedAssets\Http\Resources\Refs;
use Modules\FixedAssets\Http\Resources\Values;
use Modules\FixedAssets\Models\DepreciationRun;

/** FA-12, FA-13: depreciation runs of the companies where the reader holds the permission. */
class RunController
{
    public function __construct(private readonly Runs $runs) {}

    public function index(ListRunsRequest $request): AnonymousResourceCollection
    {
        $query = DepreciationRun::query()->with(['company', 'poster', 'reverser']);
        $request->applyCompanyFilters($query, 'period');

        return AssetController::page($query->orderByDesc('period')->orderByDesc('created_at')->paginate($request->perPage())->withQueryString(),
            fn (DepreciationRun $run) => RecordResources::run($request, $run));
    }

    public function show(ShowRunRequest $request, DepreciationRun $faRun): JsonResponse
    {
        return response()->json(['data' => RecordResources::run($request, $faRun->load(['company', 'poster', 'reverser']), detail: true)]);
    }

    /** Nothing saved: each asset's charge of the month. */
    public function preview(PreviewRunRequest $request): JsonResponse
    {
        $company = $request->company();
        $preview = $this->runs->preview($company, (string) $request->validated('period'));
        $values = Values::atCompany($request, $company->id);
        $money = fn (int $minor) => Values::money($values, $minor, $preview['currency']);

        return response()->json(['data' => [
            'period' => substr($preview['period'], 0, 7),
            'period_end' => $preview['period_end'],
            'convention' => $preview['convention'],
            'total' => $money($preview['total']),
            'assets_count' => count($preview['lines']),
            'closed_through' => $preview['closed_through'],
            'next_period' => $preview['next_period'] === null ? null : substr($preview['next_period'], 0, 7),
            'lines' => array_map(fn (array $line) => [
                'asset' => Refs::asset($line['asset']),
                'from' => $line['from'],
                'to' => $line['to'],
                'units' => $line['units'],
                'amount' => $money($line['amount']),
                'accumulated' => $money($line['accumulated_after']),
                'book_value' => $money($line['book_value_after']),
            ], $preview['lines']),
        ]]);
    }

    public function store(StoreRunRequest $request): JsonResponse
    {
        $run = $this->runs->post($request->company(), (string) $request->validated('period'), $request->user());

        return response()->json(['data' => RecordResources::run($request, $run->load(['company', 'poster', 'reverser']), detail: true)], 201);
    }

    public function reverse(ReverseRunRequest $request, DepreciationRun $faRun): JsonResponse
    {
        $run = $this->runs->reverse($faRun, $request->user(), (string) $request->validated('reason'));

        return response()->json(['data' => RecordResources::run($request, $run->load(['company', 'poster', 'reverser']), detail: true)]);
    }
}
