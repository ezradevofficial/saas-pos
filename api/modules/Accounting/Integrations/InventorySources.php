<?php

namespace Modules\Accounting\Integrations;

use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Modules\Accounting\Posting\SourceDocument;
use Modules\Inventory\Events\StockMoved;

/**
 * ACC-27: stock postings (Inventory's StockMoved) as posting sources, in
 * the company's base currency. Each movement changes the stock account by
 * what it changed the company's valuation by (its value less its cost
 * variance, INV-12), its counterpart by the opposite of its value, and
 * the cost variance goes to `cost_variance`, so the stock account always
 * equals the inventory valuation. All lines sit on the debit side with
 * signed amounts (a negative debit posts as a credit).
 *
 * | Inventory source            | Source type                | Counter field              |
 * | pos.sale, pos.refund, void  | inventory.cost_of_sales    | cost_of_sales.{sale line}  |
 * | procurement.goods_receipt   | inventory.receipt          | grni, landed_cost          |
 * | procurement.landed_cost     | inventory.landed_cost      | landed_cost                |
 * | inventory.opening, .import  | inventory.opening          | opening                    |
 * | inventory.adjustment        | inventory.adjustment       | adjustment                 |
 * | inventory.count             | inventory.count            | adjustment                 |
 * | inventory.transfer          | inventory.transfer_loss    | loss                       |
 * | stores.* (Stores issues)    | nothing: Stores posts them | (StoreConsumptionValued)   |
 * | anything else               | inventory.other            | offset                     |
 *
 * A receipt credits GRNI with its goods value and `landed_cost` (the
 * landed cost clearing account) with the landed cost allocated to it
 * before it posted (`landed_minor`); a cost adjustment for landed costs
 * allocated after it (`inventory.landed_cost`) credits the same clearing
 * account, which the charges' own bills debit (LandedCostBills).
 *
 * Transfers between a company's locations do not change its cost
 * (INV-12): `transfer_out` and `transfer_in` post nothing; only what was
 * lost on the way does. A sale's stock lines are kept apart per sale line
 * (`stock.{line}`, `cost_of_sales.{line}`), so cost of sales matches the
 * sale line by line. The source id is the posting's first movement
 * (smallest id): stable on replay, distinct for two postings of one
 * document (a transfer's dispatch and receipt).
 */
class InventorySources
{
    private const TYPES = [
        'pos.sale' => ['inventory.cost_of_sales', 'cost_of_sales'],
        'pos.refund' => ['inventory.cost_of_sales', 'cost_of_sales'],
        'pos.void' => ['inventory.cost_of_sales', 'cost_of_sales'],
        'procurement.goods_receipt' => ['inventory.receipt', 'grni'],
        // INV-25: landed costs allocated after a receipt (value-only cost adjustments).
        'procurement.landed_cost' => ['inventory.landed_cost', 'landed_cost'],
        'inventory.opening' => ['inventory.opening', 'opening'],
        'inventory.import' => ['inventory.opening', 'opening'],
        'inventory.adjustment' => ['inventory.adjustment', 'adjustment'],
        'inventory.count' => ['inventory.count', 'adjustment'],
        'inventory.transfer' => ['inventory.transfer_loss', 'loss'],
    ];

    private const NO_COST_CHANGE = ['transfer_out', 'transfer_in'];

    /** The posting source, or null when the posting changed no value. */
    public function stockMoved(StockMoved $event): ?SourceDocument
    {
        // Review #5: Stores issues and returns post from Stores' own event (per-line department, cost centre,
        // value and cost variance: StoreConsumptionValued, once feat/stores lands), never from their stock moves.
        if ($event->movements === [] || str_starts_with($event->sourceType, 'stores.')) {
            return null;
        }

        [$type, $counter] = self::TYPES[$event->sourceType] ?? ['inventory.other', 'offset'];
        $perLine = $type === 'inventory.cost_of_sales';
        $amounts = [];
        $add = function (string $field, BigInteger $minor) use (&$amounts): void {
            $amounts[$field] = (string) BigInteger::of($amounts[$field] ?? '0')->plus($minor);
        };

        foreach ($event->movements as $movement) {
            if (in_array($movement['kind'], self::NO_COST_CHANGE, true)) {
                continue;
            }

            $value = BigInteger::of((string) $movement['value_minor']);
            $variance = BigInteger::of((string) ($movement['cost_variance_minor'] ?? '0'));
            $line = $perLine ? '.'.($movement['source_line_id'] ?? $movement['id']) : '';
            // Stage 7B closing review: a receipt's landed part (charges allocated before it posted) is
            // credited to the landed cost clearing account, its goods to GRNI (what the invoice clears).
            $landed = $type === 'inventory.receipt' ? BigInteger::of((string) ($movement['landed_minor'] ?? '0')) : BigInteger::zero();
            $add("stock{$line}", $value->minus($variance));
            $add("{$counter}{$line}", $value->minus($landed)->negated());
            $add('landed_cost', $landed->negated());
            $add('cost_variance', $variance);
        }

        $amounts = array_filter($amounts, fn (string $minor) => $minor !== '0');

        if ($amounts === []) {
            return null;
        }

        $ids = array_column($event->movements, 'id');
        sort($ids);

        return new SourceDocument(
            companyId: $event->companyId,
            sourceType: $type,
            sourceId: $ids[0],
            date: CarbonImmutable::parse($event->occurredAt ?? 'now'),
            currency: $event->currency,
            amounts: $amounts,
            dimensions: array_filter(['branch_id' => $event->branchId]),
            // ACC-17: the document's number, never the raw source key; a readable description.
            reference: $event->reference === null ? null : mb_substr($event->reference, 0, 80),
            memo: Integrations::memo($type, $event->reference),
            link: ['kind' => 'inventory.movements', 'id' => $event->sourceId, 'source_type' => $event->sourceType],
        );
    }
}
