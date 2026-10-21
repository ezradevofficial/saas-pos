<?php

namespace Modules\POS\Fiscal;

use App\Core\Privacy\Retention;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * NFR-08, POS-10 (review M3): a customer's tax identity on the POS's fiscal
 * documents. PosFiscalSource reads the live party (tax_id and name) when it
 * builds a sale, refund or void for KRA eTIMS or the DGI, so anonymising a
 * customer who bought on fiscal documents would change the buyer of later
 * refunds and voids, or of documents still being sent.
 *
 * - While any of the customer's fiscal documents is queued, sending,
 *   retrying or needs attention, anonymisation waits (`fiscal_pending`).
 * - Once the customer has fiscal sales, the party keeps its tax_id and
 *   legal name ("retained for legal obligation"), and its display name
 *   becomes the legal name when there is one (else it is kept as printed);
 *   everything else is anonymised.
 *
 * Storing a buyer snapshot on each sale would remove this need; it changes
 * the POS schema and waits for the owner (docs/privacy.md, decisions).
 */
class FiscalCustomerRetention
{
    public const PENDING = ['queued', 'sending', 'retrying', 'needs_attention'];

    public function for(string $partyId): ?Retention
    {
        $db = DB::connection(TenantContext::CONNECTION);
        $sales = fn (Builder $q) => $q->from('pos_sales')->where('customer_id', $partyId)->select('id');
        $documents = $db->table('fiscal_submissions')->where(function (Builder $q) use ($sales) {
            $q->whereIn('document_id', $sales)
                ->orWhereIn('document_id', fn (Builder $r) => $r->from('pos_refunds')->whereIn('sale_id', $sales)->select('id'))
                ->orWhereIn('document_id', fn (Builder $v) => $v->from('pos_sale_voids')->whereIn('sale_id', $sales)->select('id'));
        });

        if (! (clone $documents)->exists()) {
            return null;
        }

        $legalName = $db->table('parties')->where('id', $partyId)->value('legal_name');
        $pending = (clone $documents)->whereIn('status', self::PENDING)->exists();

        return new Retention(
            keep: ['parties' => ['tax_id' => 'keep', 'legal_name' => 'keep', 'name' => $legalName !== null ? 'copy:legal_name' : 'keep']],
            reason: 'fiscal_records',
            blockedBy: $pending ? 'fiscal_pending' : null,
        );
    }
}
