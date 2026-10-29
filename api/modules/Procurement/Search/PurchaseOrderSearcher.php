<?php

namespace Modules\Procurement\Search;

use App\Core\Search\RecordHit;
use App\Core\Search\RecordSearcher;
use App\Core\Search\SearchContext;
use App\Core\Search\SearchFormat;
use App\Core\Search\SearchTerm;
use Modules\Procurement\Models\Order;
use Modules\Procurement\ProcurementServiceProvider;

/**
 * LAY-01: purchase orders by number, at the branches where the user holds
 * `procurement.po.view` (RBAC-04: an order is a branch record). The number
 * has a trigram index.
 */
class PurchaseOrderSearcher implements RecordSearcher
{
    public function type(): string
    {
        return 'purchase_order';
    }

    public function module(): string
    {
        return ProcurementServiceProvider::MODULE;
    }

    public function permission(): string
    {
        return 'procurement.po.view';
    }

    public function search(SearchTerm $term, SearchContext $context): array
    {
        $query = Order::query()->whereRaw('number ilike ?', [$term->contains()]);
        $context->whereAt($query, 'branch', 'branch_id');

        return $query
            ->orderByDesc('order_date')
            ->limit($term->limit)
            ->get(['id', 'number', 'currency', 'total_minor'])
            ->map(fn (Order $order) => new RecordHit(
                $order->id,
                (string) $order->number,
                SearchFormat::money($order->total_minor, $order->currency),
                "/procurement/orders/{$order->id}",
            ))
            ->all();
    }
}
