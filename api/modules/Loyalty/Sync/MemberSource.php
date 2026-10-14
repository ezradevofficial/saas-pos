<?php

namespace Modules\Loyalty\Sync;

use App\Core\Sync\Contracts\IncrementalSource;
use App\Core\Sync\DeviceScope;
use App\Core\Sync\Sources\Iso;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\LoyaltyServiceProvider;

/**
 * LOY-05, LOY-15, NFR-04: the balances a till checks offline, one row per
 * account of its company (`loyalty_accounts`, stamped on every posting):
 * a customer's points balance, or their credit account's currency, balance
 * and status. The credit limit comes with the core `customers` entity.
 */
class MemberSource implements IncrementalSource
{
    public function key(): string
    {
        return 'loyalty_members';
    }

    public function module(): string
    {
        return LoyaltyServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function table(): string
    {
        return 'loyalty_accounts';
    }

    public function visible(Builder $query, DeviceScope $scope): Builder
    {
        return $query->where('loyalty_accounts.company_id', $scope->companyId());
    }

    public function rows(array $ids, DeviceScope $scope): array
    {
        return $this->visible(DB::connection(TenantContext::CONNECTION)->table('loyalty_accounts'), $scope)
            ->whereIn('id', $ids)
            ->get(['id', 'party_id', 'kind', 'currency', 'balance', 'status', 'updated_at'])
            ->mapWithKeys(fn (object $a) => [$a->id => [
                'id' => $a->id,
                'customer_id' => $a->party_id,
                'kind' => $a->kind,
                'currency' => $a->currency,
                'balance' => (string) $a->balance,
                'status' => $a->status,
                'updated_at' => Iso::of($a->updated_at),
            ]])
            ->all();
    }
}
