<?php

namespace App\Core\MasterData\Items\Http\Controllers;

use App\Core\Http\ApiException;
use App\Core\MasterData\Items\Http\Requests\ListUomsRequest;
use App\Core\MasterData\Items\Http\Requests\StoreUomRequest;
use App\Core\MasterData\Items\Http\Requests\UomActionRequest;
use App\Core\MasterData\Items\Http\Requests\UomRequest;
use App\Core\MasterData\Items\Http\Requests\UpdateUomRequest;
use App\Core\MasterData\Items\Http\Resources\UomResource;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MD-02: the tenant's units of measure. Codes are unique among active
 * units, case-insensitively. A unit active items use (as base or another
 * unit) is not archived (`uom_in_use`). Archived, never deleted (TEN-06);
 * audited as `core.uom.*` (MD-07).
 */
class UomController
{
    public function index(ListUomsRequest $request): AnonymousResourceCollection
    {
        return UomResource::collection(
            $request->applyStatus(Uom::query())->orderBy('code')->orderBy('id')->paginate($request->perPage())->withQueryString(),
        );
    }

    public function store(StoreUomRequest $request): JsonResponse
    {
        $data = $request->validated();

        $uom = $this->unique(fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data) {
            $this->assertCodeFree($data['code'], null);

            return Uom::create($data);
        }));

        return UomResource::make($uom->refresh())->response()->setStatusCode(201);
    }

    public function show(UomRequest $request, Uom $uom): UomResource
    {
        return UomResource::make($uom);
    }

    public function update(UpdateUomRequest $request, Uom $uom): UomResource
    {
        $data = $request->validated();

        $uom = $this->unique(fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($uom, $data) {
            $uom = Uom::query()->whereKey($uom->id)->lockForUpdate()->firstOrFail();

            if (isset($data['code']) && ! $uom->isArchived()) {
                $this->assertCodeFree($data['code'], $uom->id);
            }

            $uom->fill($data)->save();

            return $uom;
        }));

        return UomResource::make($uom->refresh());
    }

    public function archive(UomActionRequest $request, Uom $uom): UomResource
    {
        if (! $uom->isArchived()) {
            $inUse = Item::query()->active()->where('base_uom_id', $uom->id)->exists()
                || ItemUom::query()->where('uom_id', $uom->id)->whereIn('item_id', Item::query()->active()->select('id'))->exists();

            if ($inUse) {
                throw new ApiException(422, 'uom_in_use', __('core.uom.in_use'));
            }

            $uom->archive();
        }

        return UomResource::make($uom);
    }

    public function restore(UomActionRequest $request, Uom $uom): UomResource
    {
        if ($uom->isArchived()) {
            $this->unique(function () use ($uom) {
                $this->assertCodeFree((string) $uom->code, $uom->id);
                $uom->restore();

                return $uom;
            });
        }

        return UomResource::make($uom);
    }

    private function assertCodeFree(string $code, ?string $exceptId): void
    {
        $taken = Uom::query()->active()->where('code', $code)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['code' => __('core.uom.code_taken', ['code' => strtoupper($code)])]);
        }
    }

    private function unique(callable $write): Uom
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => __('core.uom.code_taken_race')]);
        }
    }
}
