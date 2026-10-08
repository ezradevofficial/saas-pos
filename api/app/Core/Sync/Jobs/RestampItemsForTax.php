<?php

namespace App\Core\Sync\Jobs;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Taxes\TaxCategoryCode;
use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * NFR-04, CP-02: after a tax change that decides whether items may be sold
 * (a rate added or confirmed, a code archived, a category's default code
 * changed), the items concerned are re-stamped so devices pull them again
 * with a fresh `sellable`. Done here, after the change commits, in batches
 * of CHUNK rows each in its own short statement: a VAT change can touch
 * every item of a tenant, and one long transaction would hold back every
 * device's sync (ADR 004). Dispatched by SyncServiceProvider.
 */
class RestampItemsForTax implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const CHUNK = 500;

    /**
     * @param  list<string>  $taxCodeIds
     * @param  list<string>  $taxCategoryIds
     */
    public function __construct(
        public string $tenantId,
        public array $taxCodeIds = [],
        public array $taxCategoryIds = [],
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(): void
    {
        $categories = collect($this->taxCategoryIds)
            ->merge($this->taxCodeIds === [] ? [] : TaxCategoryCode::query()->whereIn('tax_code_id', $this->taxCodeIds)->pluck('tax_category_id'))
            ->filter()->unique()->values()->all();

        if ($categories === []) {
            return;
        }

        Item::query()->whereIn('tax_category_id', $categories)->select('id')->chunkById(self::CHUNK, function (Collection $items) {
            // The sync_stamp trigger gives each row a new change marker.
            DB::connection(TenantContext::CONNECTION)->table('items')->whereIn('id', $items->pluck('id')->all())->update(['sync_seq' => 0]);
        });
    }
}
