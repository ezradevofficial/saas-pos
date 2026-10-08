<?php

namespace App\Core\MasterData\Dimensions\Http\Controllers;

use App\Core\Http\ApiException;
use App\Core\MasterData\Dimensions\Dimension;
use App\Core\MasterData\Dimensions\Http\Requests\DimensionActionRequest;
use App\Core\MasterData\Dimensions\Http\Requests\DimensionRequest;
use App\Core\MasterData\Dimensions\Http\Requests\ListDimensionsRequest;
use App\Core\MasterData\Dimensions\Http\Requests\StoreDimensionRequest;
use App\Core\MasterData\Dimensions\Http\Requests\UpdateDimensionRequest;
use App\Core\MasterData\Dimensions\Http\Resources\DimensionResource;
use App\Core\Tenancy\Archiver;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MD-05: a company's departments, cost centres and projects (one
 * controller; the route's `dimension_type` names the kind). Writes that
 * touch the tree lock the company row, so two moves cannot build a cycle
 * together; the parent is re-checked under the lock. A row with active
 * children is not archived (`dimension_in_use`); a child is restored after
 * its parent (`parent_archived`). Archived, never deleted (TEN-06).
 */
class DimensionController
{
    public function __construct(private readonly Archiver $archiver) {}

    public function index(ListDimensionsRequest $request, Company $company): AnonymousResourceCollection
    {
        $query = $request->model()::query()->where('company_id', $company->id);

        return DimensionResource::collection(
            $request->applyStatus($query)->orderBy('code')->orderBy('id')->paginate($request->perPage())->withQueryString(),
        );
    }

    public function store(StoreDimensionRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();
        $model = $request->model();

        $dimension = $this->unique(fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $data, $model) {
            $this->archiver->lockActive(Company::class, $company->id);
            $dimension = new $model(['company_id' => $company->id]);
            $this->assertParent($dimension, $data['parent_id'] ?? null);
            $dimension->fill($this->attributes($data))->save();

            return $dimension;
        }));

        return DimensionResource::make($dimension->refresh())->response()->setStatusCode(201);
    }

    public function show(DimensionRequest $request, Dimension $dimension): DimensionResource
    {
        return DimensionResource::make($dimension);
    }

    public function update(UpdateDimensionRequest $request, Dimension $dimension): DimensionResource
    {
        $data = $request->validated();

        $updated = $this->unique(fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($dimension, $data) {
            Company::query()->whereKey($dimension->company_id)->lockForUpdate()->firstOrFail();
            $locked = $dimension::query()->whereKey($dimension->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('parent_id', $data)) {
                $this->assertParent($locked, $data['parent_id']);
            }

            $locked->fill($this->attributes($data))->save();

            return $locked;
        }));

        return DimensionResource::make($updated->refresh());
    }

    public function archive(DimensionActionRequest $request, Dimension $dimension): DimensionResource
    {
        $archived = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($dimension) {
            Company::query()->whereKey($dimension->company_id)->lockForUpdate()->firstOrFail();
            $locked = $dimension::query()->whereKey($dimension->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isArchived()) {
                if ($dimension::query()->active()->where('parent_id', $locked->id)->exists()) {
                    throw new ApiException(422, 'dimension_in_use', __('core.dimension.in_use'));
                }

                $locked->archive();
            }

            return $locked;
        });

        return DimensionResource::make($archived);
    }

    public function restore(DimensionActionRequest $request, Dimension $dimension): DimensionResource
    {
        $restored = $this->unique(fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($dimension) {
            $this->archiver->lockActive(Company::class, $dimension->company_id);
            $locked = $dimension::query()->whereKey($dimension->id)->lockForUpdate()->firstOrFail();

            if ($locked->isArchived()) {
                if ($locked->parent_id !== null && $dimension::query()->whereKey($locked->parent_id)->whereNotNull('archived_at')->exists()) {
                    throw new ApiException(422, 'parent_archived', __('core.dimension.parent_archived'));
                }

                $locked->restore();
            }

            return $locked;
        }));

        return DimensionResource::make($restored);
    }

    /** Re-checked under the company lock: the parent is active, of the same company, and not this row or beneath it. */
    private function assertParent(Dimension $dimension, ?string $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = $dimension::query()->whereKey($parentId)->first();

        $message = match (true) {
            $parent === null || $parent->isArchived() || $parent->company_id !== $dimension->company_id => __('core.dimension.parent_other_company'),
            $dimension->exists && $dimension->isAncestorOf($parentId) => __('core.dimension.parent_cycle'),
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['parent_id' => $message]);
        }
    }

    /** A code taken meanwhile (the active-code unique index) answers as a validation error. */
    private function unique(callable $write): Dimension
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => __('core.dimension.code_taken')]);
        }
    }

    private function attributes(array $data): array
    {
        return array_intersect_key($data, array_flip(['code', 'name', 'parent_id', 'owner_user_id']));
    }
}
