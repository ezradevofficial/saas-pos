<?php

namespace Modules\POS\Fiscal;

use Illuminate\Database\ConnectionInterface;

/**
 * POS-08, POS-10, NFR-08 (owner decision 2026-10-22): gives sales stored
 * before the buyer snapshot their customer's tax identity as it is now
 * (what their fiscal documents were built from), when the customer has a
 * tax id. Run by the migration as the schema owner (every tenant); in
 * chunks of sales by id, each its own short transaction, so tills keep
 * uploading meanwhile. Rerunnable: a sale with a snapshot is left alone.
 */
final class BuyerSnapshotBackfill
{
    /** @return int sales given a snapshot */
    public static function run(ConnectionInterface $db, int $chunk = 2000): int
    {
        $last = null;
        $updated = 0;

        do {
            $ids = $db->table('pos_sales')->whereNotNull('customer_id')->whereNull('buyer_tax_id')
                ->when($last !== null, fn ($q) => $q->where('id', '>', $last))
                ->orderBy('id')->limit($chunk)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $updated += $db->transaction(fn () => $db->update(<<<'SQL'
                update pos_sales s
                set buyer_tax_id = btrim(p.tax_id), buyer_name = p.name
                from parties p
                where p.id = s.customer_id and p.tenant_id = s.tenant_id and btrim(coalesce(p.tax_id, '')) <> ''
                  and s.buyer_tax_id is null and s.id = any(?::uuid[])
                SQL, ['{'.implode(',', $ids).'}']));

            $last = end($ids);
        } while (count($ids) === $chunk);

        return $updated;
    }
}
