<?php

namespace Modules\VanSales\Credit;

use App\Core\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Modules\VanSales\Models\CreditEntry;

/**
 * VAN-13: the route credit ledger. A customer's balance per currency is the
 * sum of its entries (what they owe, positive). Entries are append-only and
 * once per (kind, source, currency): a retry adds nothing.
 */
class Ledger
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** @return array<string, int> currency => balance in minor units */
    public function balances(string $customerId): array
    {
        return CreditEntry::query()->where('customer_id', $customerId)->groupBy('currency')->selectRaw('currency, sum(amount_minor) as amount')
            ->orderBy('currency')->pluck('amount', 'currency')->map(fn ($v) => (int) $v)->all();
    }

    /** The balance in $currency up to $upTo, leaving out the entries of $exceptSource (the sale being judged). */
    public function balance(string $customerId, string $currency, ?CarbonInterface $upTo = null, ?string $exceptSource = null): int
    {
        return (int) CreditEntry::query()->where('customer_id', $customerId)->where('currency', $currency)
            ->when($upTo !== null, fn ($q) => $q->where('occurred_at', '<=', $upTo))
            ->when($exceptSource !== null, fn ($q) => $q->where('source_id', '<>', $exceptSource))
            ->sum('amount_minor');
    }

    /** Adds the entry once; false when it was already there. */
    public function add(string $companyId, string $customerId, ?string $runId, string $kind, string $sourceId, string $currency, int $amountMinor, CarbonInterface $at): bool
    {
        if ($amountMinor === 0) {
            return false;
        }

        return CreditEntry::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenants->require(),
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'run_id' => $runId,
            'kind' => $kind,
            'source_id' => $sourceId,
            'currency' => $currency,
            'amount_minor' => $amountMinor,
            'occurred_at' => $at,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }
}
