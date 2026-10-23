<?php

namespace Modules\POS\Fiscal;

use App\Core\Privacy\Retention;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * NFR-08, POS-10 (owner decision 2026-10-22): anonymising a customer who
 * bought on fiscal documents. Each sale keeps its buyer snapshot
 * (`pos_sales.buyer_tax_id`, `buyer_name`), and PosFiscalSource builds a
 * sale, its refunds and its void from it, never from the party. So:
 *
 * - While any of the customer's fiscal documents is queued, sending,
 *   retrying or needs attention, anonymisation waits (`fiscal_pending`):
 *   the authority has not yet accepted what names them.
 * - Otherwise the party is fully anonymised. The sales' buyer snapshots
 *   stay as printed on the fiscal documents, reported as retained for a
 *   legal obligation (`fiscal_records`).
 */
class FiscalCustomerRetention
{
    public const PENDING = ['queued', 'sending', 'retrying', 'needs_attention'];

    public function for(string $partyId): ?Retention
    {
        $db = DB::connection(TenantContext::CONNECTION);
        $sales = fn (Builder $q) => $q->from('pos_sales')->where('customer_id', $partyId)->select('id');
        $pending = $db->table('fiscal_submissions')->whereIn('status', self::PENDING)->where(function (Builder $q) use ($sales) {
            $q->whereIn('document_id', $sales)
                ->orWhereIn('document_id', fn (Builder $r) => $r->from('pos_refunds')->whereIn('sale_id', $sales)->select('id'))
                ->orWhereIn('document_id', fn (Builder $v) => $v->from('pos_sale_voids')->whereIn('sale_id', $sales)->select('id'));
        })->exists();
        $snapshots = $db->table('pos_sales')->where('customer_id', $partyId)->whereNotNull('buyer_tax_id')->exists();

        if (! $pending && ! $snapshots) {
            return null;
        }

        return new Retention(
            keep: $snapshots ? ['pos_sales' => ['buyer_tax_id' => 'keep', 'buyer_name' => 'keep']] : [],
            reason: 'fiscal_records',
            blockedBy: $pending ? 'fiscal_pending' : null,
        );
    }
}
