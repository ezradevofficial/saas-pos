<?php

namespace Modules\Restaurant\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Restaurant\Http\Requests\ArchiveRequest;
use Modules\Restaurant\Http\Requests\StoreStationRequest;
use Modules\Restaurant\Http\Requests\UpdateStationRequest;
use Modules\Restaurant\Http\Requests\ViewRequest;
use Modules\Restaurant\Http\Resources\Presenters;
use Modules\Restaurant\Models\Station;
use Modules\Restaurant\Support\Places;
use Modules\Restaurant\Support\Setup;

/** REST-08: stations and the item categories routed to them. */
class StationController
{
    public function __construct(private readonly Places $places, private readonly Setup $setup) {}

    public function index(ViewRequest $request): JsonResponse
    {
        $query = Station::query()->with('routes')->orderBy('sort')->orderBy('name')->orderBy('id');
        $this->places->filterLocations($query, $request->user(), $request->permission());

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->input('location_id'));
        }

        if (! $request->boolean('archived')) {
            $query->whereNull('archived_at');
        }

        return response()->json(['data' => $query->get()->map(fn (Station $s) => Presenters::station($s))->all()]);
    }

    public function store(StoreStationRequest $request): JsonResponse
    {
        $place = $this->places->open((string) $request->input('location_id'));
        $station = $this->setup->station(null, $request->validated(), ['company_id' => $place['company_id'], 'branch_id' => $place['branch_id']], (string) $request->input('location_id'));

        return response()->json(['data' => Presenters::station($station->refresh())], 201);
    }

    public function update(UpdateStationRequest $request, Station $restStation): JsonResponse
    {
        $station = $this->setup->station($restStation, $request->validated());

        return response()->json(['data' => Presenters::station($station->refresh())]);
    }

    public function archive(ArchiveRequest $request, Station $restStation): JsonResponse
    {
        $restStation->is_default = false;
        $restStation->archive();

        return response()->json(['data' => Presenters::station($restStation)]);
    }
}
