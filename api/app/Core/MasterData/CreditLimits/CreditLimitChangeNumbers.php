<?php

namespace App\Core\MasterData\CreditLimits;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Numbers credit limit change requests per tenant: CLC-000001, CLC-000002 …
 *
 * A stand-in until the numbering service (NUM-01, Phase 5) exists: the only
 * place that knows the format, so swapping it touches this class alone.
 * Call inside the creating transaction: a transaction-level advisory lock
 * per tenant serialises concurrent requests until commit, and the unique
 * (tenant_id, seq) index is the backstop. Reads run under RLS, so the
 * maximum is the tenant's own.
 */
class CreditLimitChangeNumbers
{
    public const PREFIX = 'CLC-';

    public const WIDTH = 6;

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return array{seq: int, number: string} */
    public function next(): array
    {
        $db = DB::connection(TenantContext::CONNECTION);
        $db->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['credit_limit_changes:'.$this->tenants->require()]);
        $seq = (int) CreditLimitChange::query()->max('seq') + 1;

        return ['seq' => $seq, 'number' => self::PREFIX.str_pad((string) $seq, self::WIDTH, '0', STR_PAD_LEFT)];
    }
}
