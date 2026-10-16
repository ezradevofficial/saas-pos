<?php

namespace Modules\POS\Contracts;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\Refund;
use Modules\POS\Models\Sale;
use Modules\POS\PosServiceProvider;

/**
 * Read contract (docs/modules/crm.md, CRM-30): POS sales in a period, in
 * the company's base currency, for another module's figures (CRM sales
 * targets), so it never reads `pos_*` tables itself: completed sales (not
 * voided) less applied refunds, before tax (CRM targets are net, stage 7D
 * closing review: a sale's base total less its base tax; a refund's base
 * total less its tax share, base total × tax ÷ total, rounded half up),
 * by the cashier who made them and/or the branch, the days read in the
 * company's time zone. Null while POS is off. The caller decides who may
 * see the figure.
 */
final class SalesTotals
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * @param  list<string>|null  $cashierIds  null: any cashier
     * @return string|null minor units of the company's base currency
     */
    public function net(string $companyId, string $from, string $to, ?array $cashierIds = null, ?string $branchId = null): ?string
    {
        if (! $this->modules->isActive(PosServiceProvider::MODULE)) {
            return null;
        }

        if ($cashierIds === []) {
            return '0';
        }

        $zone = Company::query()->whereKey($companyId)->value('timezone') ?: 'UTC';
        $start = CarbonImmutable::parse($from, $zone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $zone)->addDay()->startOfDay()->utc();
        $filter = function ($query) use ($companyId, $cashierIds, $branchId) {
            $query->where('company_id', $companyId);

            if ($cashierIds !== null) {
                $query->whereIn('cashier_id', $cashierIds);
            }

            if ($branchId !== null) {
                $query->where('branch_id', $branchId);
            }
        };

        $sales = Sale::query()->where('status', Sale::COMPLETED)->where('sold_at', '>=', $start)->where('sold_at', '<', $end)->where($filter)
            ->sum(DB::raw('base_total_minor - base_tax_minor'));
        $refunds = Refund::query()->where('status', 'applied')->where('refunded_at', '>=', $start)->where('refunded_at', '<', $end)->where($filter)
            ->sum(DB::raw('base_total_minor - round(base_total_minor::numeric * tax_minor / total_minor)'));

        return (string) BigInteger::of((string) $sales)->minus((string) $refunds);
    }
}
