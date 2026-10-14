<?php

namespace Modules\Procurement\Http\Controllers;

use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Procurement\Documents\LandedCosts;
use Modules\Procurement\Http\Lists\LandedCostList;
use Modules\Procurement\Http\Requests\DocumentActionRequest;
use Modules\Procurement\Http\Requests\ListLandedCostsRequest;
use Modules\Procurement\Http\Requests\ShowDocumentRequest;
use Modules\Procurement\Http\Requests\StoreLandedCostRequest;
use Modules\Procurement\Http\Requests\UpdateLandedCostRequest;
use Modules\Procurement\Http\Resources\LandedCostResource;
use Modules\Procurement\Models\LandedCost;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PRC-17: landed costs and their allocation over GRN lines. */
class LandedCostController
{
    public function __construct(private readonly LandedCosts $landedCosts) {}

    public function index(ListLandedCostsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = LandedCost::query()->with(LandedCostList::RELATIONS);
        $request->applyFilters($query);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return LandedCostResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowDocumentRequest $request, LandedCost $procurementLandedCost): LandedCostResource
    {
        return $this->detail($procurementLandedCost);
    }

    public function store(StoreLandedCostRequest $request): JsonResponse
    {
        return $this->detail($this->landedCosts->create($request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    public function update(UpdateLandedCostRequest $request, LandedCost $procurementLandedCost): LandedCostResource
    {
        return $this->detail($this->landedCosts->update($procurementLandedCost, $request->validated(), $request->user()));
    }

    public function post(DocumentActionRequest $request, LandedCost $procurementLandedCost): LandedCostResource
    {
        return $this->detail($this->landedCosts->post($procurementLandedCost, $request->user()));
    }

    public function cancel(DocumentActionRequest $request, LandedCost $procurementLandedCost): LandedCostResource
    {
        return $this->detail($this->landedCosts->cancel($procurementLandedCost, $request->user(), (string) $request->validated('reason')));
    }

    private function detail(LandedCost $landedCost): LandedCostResource
    {
        return LandedCostResource::make($landedCost->refresh()->load(LandedCostList::RELATIONS))->detail();
    }
}
