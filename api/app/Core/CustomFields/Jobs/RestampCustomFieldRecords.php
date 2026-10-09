<?php

namespace App\Core\CustomFields\Jobs;

use App\Core\CustomFields\CustomFieldEntities;
use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * NFR-04, CF-03: after a custom field starts or stops travelling to the
 * till (`show_on_pos` toggled, or such a field archived or restored), the
 * entity's synced records are re-stamped so devices pull them again with
 * the right `custom` values. In batches of CHUNK, each its own short
 * statement, after the change commits (as RestampItemsForTax; ADR 004).
 */
class RestampCustomFieldRecords implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const CHUNK = 500;

    public function __construct(public string $tenantId, public string $entity) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(CustomFieldEntities $entities): void
    {
        $entity = $entities->find($this->entity);
        $records = $entity?->syncedRecords();

        if ($records === null) {
            return;
        }

        $table = $entity->table();

        $records->select('id')->chunkById(self::CHUNK, function (Collection $rows) use ($table) {
            // The sync_stamp trigger gives each row a new change marker.
            DB::connection(TenantContext::CONNECTION)->table($table)->whereIn('id', $rows->pluck('id')->all())->update(['sync_seq' => 0]);
        });
    }
}
