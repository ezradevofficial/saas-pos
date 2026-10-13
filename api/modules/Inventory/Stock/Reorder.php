<?php

namespace Modules\Inventory\Stock;

use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Events\StockLow;
use Modules\Inventory\Models\Balance;
use Modules\Inventory\Models\ReorderRule;

/**
 * INV-11: reorder levels. After a posting, each place it touched is
 * compared with its active rule: stock at or below the level marks the rule
 * low (once: a conditional update, so concurrent postings alert once) and
 * raises StockLow after commit; stock above the level re-arms it.
 */
class Reorder
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** @param list<array{0: string, 1: string}> $places item id, location id */
    public function check(array $places): void
    {
        foreach ($places as [$itemId, $locationId]) {
            $rule = ReorderRule::query()->whereNull('archived_at')->where('item_id', $itemId)->where('location_id', $locationId)->first();

            if ($rule === null) {
                continue;
            }

            $this->compare($rule);
        }
    }

    /** Compare one rule with the stock at its place now (also after the rule itself changed). */
    public function compare(ReorderRule $rule): void
    {
        $qty = BigDecimal::of((string) (Balance::query()->where('item_id', $rule->item_id)->where('location_id', $rule->location_id)->value('qty') ?? '0'));
        $level = BigDecimal::of((string) $rule->reorder_level);
        $db = DB::connection(TenantContext::CONNECTION);

        if ($qty->isLessThanOrEqualTo($level)) {
            $marked = $db->table('inventory_reorder_rules')->where('id', $rule->id)->whereNull('low_since')->update(['low_since' => now()]);

            if ($marked === 1) {
                StockLow::dispatch(
                    $this->tenants->require(),
                    $rule->company_id,
                    $rule->item_id,
                    $rule->location_id,
                    (string) Qty::show($qty),
                    (string) Qty::show($rule->reorder_level),
                    (string) Qty::show($rule->reorder_qty),
                );
            }

            return;
        }

        $db->table('inventory_reorder_rules')->where('id', $rule->id)->whereNotNull('low_since')->update(['low_since' => null]);
    }
}
