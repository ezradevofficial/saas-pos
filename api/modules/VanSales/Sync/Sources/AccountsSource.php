<?php

namespace Modules\VanSales\Sync\Sources;

use App\Core\MasterData\Parties\Party;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\VanSales\Credit\Ledger;
use Modules\VanSales\Models\Route;
use Modules\VanSales\Models\Stop;
use Modules\VanSales\VanSalesServiceProvider;

/** VAN-11, VAN-13, NFR-04: the credit limit and route balance of every customer on the van's routes, for credit decisions offline. */
class AccountsSource implements SnapshotSource
{
    public function key(): string
    {
        return 'van_sales_accounts';
    }

    public function module(): string
    {
        return VanSalesServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $van = VanSource::vanOf($scope);

        if ($van === null) {
            return [];
        }

        $routes = Route::query()->whereNull('archived_at')->where('company_id', $van->company_id)->where('branch_id', $van->branch_id)
            ->where(fn ($q) => $q->where('van_id', $van->id)->orWhereNull('van_id'))->pluck('id');
        $ids = Stop::query()->whereNull('archived_at')->whereIn('route_id', $routes)->distinct()->pluck('customer_id')->all();
        $ledger = app(Ledger::class);

        return Party::query()->whereKey($ids)->orderBy('id')->get()->map(fn (Party $party) => [
            'id' => $party->id,
            'credit_limit_minor' => $party->credit_limit_minor === null ? null : (string) $party->credit_limit_minor,
            'credit_limit_currency' => $party->credit_limit_currency,
            'balances' => collect($ledger->balances($party->id))->map(fn (int $amount, string $currency) => ['currency' => $currency, 'amount_minor' => (string) $amount])->values()->all(),
        ])->values()->all();
    }
}
