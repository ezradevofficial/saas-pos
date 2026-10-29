<?php

namespace App\Core\Search\Searchers;

use App\Core\Billing\Models\BillingInvoice;
use App\Core\Search\RecordHit;
use App\Core\Search\RecordSearcher;
use App\Core\Search\SearchContext;
use App\Core\Search\SearchFormat;
use App\Core\Search\SearchTerm;

/**
 * LAY-01, SUB-04: the tenant's own subscription invoices by number. The
 * subscription is tenant-wide (`core.subscription.view` at the tenant), so a
 * user holding it only at a company or below finds nothing here (RBAC-04).
 */
class BillingInvoiceSearcher implements RecordSearcher
{
    public function type(): string
    {
        return 'billing_invoice';
    }

    public function module(): string
    {
        return 'core';
    }

    public function permission(): string
    {
        return 'core.subscription.view';
    }

    public function search(SearchTerm $term, SearchContext $context): array
    {
        if (! $context->visible->all) {
            return [];
        }

        return BillingInvoice::query()
            ->whereRaw('number ilike ?', [$term->contains()])
            ->orderByDesc('issued_at')
            ->limit($term->limit)
            ->get(['id', 'number', 'currency', 'total_minor'])
            ->map(fn (BillingInvoice $invoice) => new RecordHit(
                $invoice->id,
                (string) $invoice->number,
                SearchFormat::money($invoice->total_minor, $invoice->currency),
                '/settings/subscription',
            ))
            ->all();
    }
}
