<?php

namespace Modules\Inventory\Events;

use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Qty;

/**
 * INV-16: a source document moved stock (once per posting). Carries each
 * movement's signed base quantity and signed value in the company's base
 * currency (minor units as digit strings), what Accounting needs to post
 * a stock journal. Dispatched after commit.
 *
 * Since stage 7B: when it happened (`occurredAt`, ISO 8601 UTC, the
 * posting date), the branch of its first location, and per movement its
 * source line (`source_line_id`: the sale line for a POS sale, so cost of
 * sales matches the sale line by line) and branch.
 */
class StockMoved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<array{id: string, kind: string, item_id: string, location_id: ?string, branch_id: ?string, source_line_id: ?string, qty: string, value_minor: string, cost_variance_minor: string}>  $movements
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $currency,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly array $movements,
        public readonly ?string $occurredAt = null,
        public readonly ?string $branchId = null,
    ) {}

    /**
     * The event of one posting's movements (each with its location's branch).
     *
     * @param  list<Movement>  $movements
     */
    public static function of(string $tenantId, string $companyId, string $currency, string $sourceType, string $sourceId, array $movements, CarbonImmutable $occurredAt): self
    {
        $branches = Location::query()->whereKey(array_values(array_unique(array_filter(array_map(fn (Movement $m) => $m->location_id, $movements)))))->pluck('branch_id', 'id');
        $rows = array_map(fn (Movement $m) => [
            'id' => $m->id, 'kind' => $m->kind, 'item_id' => $m->item_id, 'location_id' => $m->location_id,
            'branch_id' => $m->location_id === null ? null : $branches->get($m->location_id),
            'source_line_id' => $m->source_line_id,
            'qty' => Qty::show($m->qty), 'value_minor' => (string) $m->value_minor, 'cost_variance_minor' => (string) $m->cost_variance_minor,
        ], $movements);

        return new self($tenantId, $companyId, $currency, $sourceType, $sourceId, $rows,
            $occurredAt->utc()->format('Y-m-d\TH:i:s.u\Z'), collect($rows)->pluck('branch_id')->filter()->first());
    }
}
