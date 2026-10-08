<?php

namespace App\Core\MasterData\Items\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Http\ApiException;
use App\Core\MasterData\Items\Http\Requests\ListUomsRequest;
use App\Core\MasterData\Items\Http\Requests\StoreUomRequest;
use App\Core\MasterData\Items\Http\Requests\UomActionRequest;
use App\Core\MasterData\Items\Http\Requests\UomRequest;
use App\Core\MasterData\Items\Http\Requests\UpdateUomRequest;
use App\Core\MasterData\Items\Http\Resources\UomResource;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemSharing;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MD-02: the tenant's units of measure. Codes are unique among active
 * units, case-insensitively. A unit active items use (as base or another
 * unit) is not archived (`uom_in_use`). Archived, never deleted (TEN-06);
 * audited as `core.uom.*` (MD-07).
 */
class UomController
{
    public function __construct(private readonly MasterDataSharing $sharing) {}

    public function index(ListUomsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->applySearch($request->applyStatus(Uom::query()), ['code' => 'code', 'name' => 'name']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return UomResource::collection($query->paginate($request->perPage())->withQueryString());
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

    /**
     * Under the items sharing lock and the unit's row lock (FOR UPDATE), so
     * an item writer (which reads its units FOR SHARE) either sees the
     * archive or is seen here.
     */
    public function archive(UomActionRequest $request, Uom $uom): UomResource
    {
        $uom = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($uom) {
            $this->sharing->lockForWrite([ItemSharing::DATA_TYPE]);
            $uom = Uom::query()->whereKey($uom->id)->lockForUpdate()->firstOrFail();

            if (! $uom->isArchived()) {
                $inUse = Item::query()->active()->where('base_uom_id', $uom->id)->exists()
                    || ItemUom::query()->where('uom_id', $uom->id)->whereIn('item_id', Item::query()->active()->select('id'))->exists();

                if ($inUse) {
                    throw new ApiException(422, 'uom_in_use', __('core.uom.in_use'));
                }

                $uom->archive();
            }

            return $uom;
        });

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
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), '"uoms_code_unique"')) {
                throw $e;
            }

            throw ValidationException::withMessages(['code' => __('core.uom.code_taken_race')]);
        }
    }
}
