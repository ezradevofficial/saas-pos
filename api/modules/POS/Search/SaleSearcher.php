<?php

namespace Modules\POS\Search;

use App\Core\Search\RecordHit;
use App\Core\Search\RecordSearcher;
use App\Core\Search\SearchContext;
use App\Core\Search\SearchFormat;
use App\Core\Search\SearchTerm;
use Modules\POS\Models\Sale;
use Modules\POS\PosServiceProvider;

/**
 * LAY-01, POS-04: sales by receipt number, at the locations where the user
 * holds `pos.sale.view` (RBAC-04). The receipt number has a trigram index.
 */
class SaleSearcher implements RecordSearcher
{
    public function type(): string
    {
        return 'sale';
    }

    public function module(): string
    {
        return PosServiceProvider::MODULE;
    }

    public function permission(): string
    {
        return 'pos.sale.view';
    }

    public function search(SearchTerm $term, SearchContext $context): array
    {
        $query = Sale::query()->whereRaw('receipt_number ilike ?', [$term->contains()]);
        $context->whereAt($query, 'location', 'location_id');

        return $query
            ->orderByDesc('sold_at')
            ->limit($term->limit)
            ->get(['id', 'receipt_number', 'currency', 'total_minor'])
            ->map(fn (Sale $sale) => new RecordHit(
                $sale->id,
                (string) $sale->receipt_number,
                SearchFormat::money($sale->total_minor, $sale->currency),
                "/pos/sales/{$sale->id}",
            ))
            ->all();
    }
}
