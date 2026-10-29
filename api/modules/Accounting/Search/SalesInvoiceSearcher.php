<?php

namespace Modules\Accounting\Search;

use App\Core\Search\RecordHit;
use App\Core\Search\RecordSearcher;
use App\Core\Search\SearchContext;
use App\Core\Search\SearchFormat;
use App\Core\Search\SearchTerm;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Accounting\Models\Document;

/**
 * LAY-01, ACC-10: sales (customer) invoices by number, in the companies where
 * the user holds `accounting.invoice.view` (RBAC-04: accounting documents are
 * company records). The number has a trigram index.
 */
class SalesInvoiceSearcher implements RecordSearcher
{
    public function type(): string
    {
        return 'sales_invoice';
    }

    public function module(): string
    {
        return AccountingServiceProvider::MODULE;
    }

    public function permission(): string
    {
        return 'accounting.invoice.view';
    }

    public function search(SearchTerm $term, SearchContext $context): array
    {
        $query = Document::query()->where('kind', 'customer_invoice')->whereRaw('number ilike ?', [$term->contains()]);
        $context->whereAt($query, 'company', 'company_id');

        return $query
            ->orderByDesc('issue_date')
            ->limit($term->limit)
            ->get(['id', 'number', 'currency', 'total_minor'])
            ->map(fn (Document $document) => new RecordHit(
                $document->id,
                (string) $document->number,
                SearchFormat::money($document->total_minor, $document->currency),
                "/accounting/documents/{$document->id}",
            ))
            ->all();
    }
}
