<?php

namespace App\Core\Tenancy;

use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * TEN-06: archive and restore companies, branches and locations.
 *
 * A tenant keeps at least one active company, branch and location
 * (`last_active`), and records are archived bottom-up: a record with active
 * children is refused (`has_active_children`). Restoring re-activates only
 * the record itself, and only under an active parent (`parent_archived`).
 */
class Archiver
{
    public function archive(Company|Branch|Location $model): Company|Branch|Location
    {
        return DB::transaction(function () use ($model) {
            // Lock the tenant's active rows of this level, so two archives
            // cannot both see the other record as still active.
            $activeIds = $model::query()->active()->lockForUpdate()->pluck('id');
            $model->refresh();

            if ($model->isArchived()) {
                return $model;
            }

            if ($activeIds->reject(fn (string $id) => $id === $model->id)->isEmpty()) {
                throw new ApiException(422, 'last_active', __('core.organisation.last_active'));
            }

            if ($this->hasActiveChildren($model)) {
                throw new ApiException(422, 'has_active_children', __('core.organisation.has_active_children'));
            }

            $model->archive();

            return $model;
        });
    }

    public function restore(Company|Branch|Location $model): Company|Branch|Location
    {
        if (! $model->isArchived()) {
            return $model;
        }

        $codeTaken = fn () => ValidationException::withMessages([
            'code' => __('core.organisation.code_taken'),
        ]);

        return DB::transaction(function () use ($model, $codeTaken) {
            // An active record never sits under an archived one.
            match (true) {
                $model instanceof Branch => $this->lockActive(Company::class, $model->company_id),
                $model instanceof Location => $this->lockActive(Branch::class, $model->branch_id),
                default => null,
            };

            // An active branch of the company may have taken the code since.
            if ($model instanceof Branch && Branch::active()
                ->where('company_id', $model->company_id)
                ->where('code', $model->code)
                ->exists()) {
                throw $codeTaken();
            }

            try {
                $model->restore();
            } catch (UniqueConstraintViolationException) {
                throw $codeTaken();
            }

            return $model;
        });
    }

    /**
     * Lock the parent row for the rest of the transaction and refuse it when
     * archived (422 `parent_archived`), so a child is never created or
     * restored under a parent being archived concurrently. Call inside a
     * transaction.
     *
     * @template T of Company|Branch|Location
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function lockActive(string $class, string $id): Company|Branch|Location
    {
        $parent = $class::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        if ($parent->isArchived()) {
            throw new ApiException(422, 'parent_archived', __('core.organisation.parent_archived'));
        }

        return $parent;
    }

    private function hasActiveChildren(Company|Branch|Location $model): bool
    {
        return match (true) {
            $model instanceof Company => $model->branches()->active()->exists(),
            $model instanceof Branch => $model->locations()->active()->exists(),
            $model instanceof Location => $model->devices()
                ->whereIn('status', [Device::STATUS_PENDING, Device::STATUS_ACTIVE])
                ->exists(),
        };
    }
}
