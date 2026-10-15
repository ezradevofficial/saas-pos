<?php

namespace Modules\HR\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\HR\Employees\Positions;
use Modules\HR\Http\Requests\ListPositionsRequest;
use Modules\HR\Http\Requests\PositionRequest;
use Modules\HR\Http\Requests\StorePositionRequest;
use Modules\HR\Http\Resources\PositionResource;
use Modules\HR\Models\Position;

/** HR-08: positions of the reader's companies. */
class PositionController
{
    public function __construct(private readonly Positions $positions) {}

    public function index(ListPositionsRequest $request): AnonymousResourceCollection
    {
        $query = $request->applyFilters(Position::query()->with(['company', 'department']), ['code', 'title'])->orderBy('code')->orderBy('id');

        return PositionResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StorePositionRequest $request): JsonResponse
    {
        return PositionResource::make($this->positions->create($request->validated())->load(['company', 'department']))->response()->setStatusCode(201);
    }

    public function update(PositionRequest $request, Position $hrPosition): PositionResource
    {
        return PositionResource::make($this->positions->update($hrPosition, $request->validated())->load(['company', 'department']));
    }

    public function archive(PositionRequest $request, Position $hrPosition): PositionResource
    {
        if (! $hrPosition->isArchived()) {
            $hrPosition->archive();
        }

        return PositionResource::make($hrPosition->refresh()->load(['company', 'department']));
    }

    public function restore(PositionRequest $request, Position $hrPosition): PositionResource
    {
        return PositionResource::make(($hrPosition->isArchived() ? $this->positions->restore($hrPosition) : $hrPosition)->load(['company', 'department']));
    }
}
