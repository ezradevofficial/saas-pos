<?php

namespace Modules\FixedAssets\Support;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * FA-12 (review #3): one transaction-scoped advisory lock per company,
 * taken by runs (post, reverse) and by whatever changes a book a run reads
 * (disposal posting, revaluation, impairment, change of estimate), so a
 * run never charges on figures another transaction is changing.
 */
final class RunLock
{
    public static function key(string $companyId): string
    {
        return 'fa_runs:'.$companyId;
    }

    /** Take the company's lock until the current transaction ends (call inside one). */
    public static function take(string $companyId): void
    {
        DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [self::key($companyId)]);
    }
}
