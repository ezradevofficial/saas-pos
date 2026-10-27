<?php

namespace Modules\Pharmacy\Sync;

use App\Core\Sync\Contracts\IncrementalSource;
use App\Core\Sync\DeviceScope;
use App\Core\Sync\Sources\Iso;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Pharmacy\PharmacyServiceProvider;

/**
 * PHA-03, PHA-20, PHA-25: the pharmacy profile of each item (class, kind,
 * lot tracking, restricted sale, usage notes, withdrawal days), incremental
 * and read-only: the till applies the prescription, pharmacist and lot rules
 * to these items offline and shows the usage notes of agrochemicals when
 * one is added. Profiles are never deleted; an item reset to `otc` just
 * stops needing anything.
 */
class ItemProfileSource implements IncrementalSource
{
    public function key(): string
    {
        return 'pharmacy_items';
    }

    public function module(): string
    {
        return PharmacyServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function table(): string
    {
        return 'pharmacy_item_profiles';
    }

    public function visible(Builder $query, DeviceScope $scope): Builder
    {
        return $query;
    }

    public function rows(array $ids, DeviceScope $scope): array
    {
        return $this->visible(DB::connection(TenantContext::CONNECTION)->table('pharmacy_item_profiles'), $scope)
            ->whereIn('id', $ids)
            ->get(['id', 'item_id', 'class', 'product_kind', 'batch_tracked', 'restricted_sale', 'usage_notes', 'withdrawal_days', 'updated_at'])
            ->mapWithKeys(fn (object $p) => [$p->id => [
                'id' => $p->id,
                'item_id' => $p->item_id,
                'class' => $p->class,
                'product_kind' => $p->product_kind,
                'batch_tracked' => (bool) $p->batch_tracked,
                'restricted_sale' => (bool) $p->restricted_sale,
                'usage_notes' => $p->usage_notes,
                'withdrawal_days' => $p->withdrawal_days,
                'updated_at' => Iso::of($p->updated_at),
            ]])
            ->all();
    }
}
