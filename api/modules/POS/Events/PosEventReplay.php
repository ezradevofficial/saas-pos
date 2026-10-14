<?php

namespace Modules\POS\Events;

use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Modules\POS\Models\Refund;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleVoid;
use Modules\POS\Sync\Records;

/**
 * Stage 7B (core EventReplays): the POS events of a period raised again,
 * as they were raised the first time: every sale sold in it
 * (SaleCompleted, a voided sale too, since its void follows), then the
 * applied voids and the applied refunds dated in it, oldest first.
 */
final class PosEventReplay
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** @param Closure(object): void $emit */
    public function __invoke(CarbonImmutable $from, CarbonImmutable $to, Closure $emit): void
    {
        $tenant = $this->tenants->require();

        foreach (Sale::query()->whereBetween('sold_at', [$from, $to])->with(['lines', 'payments'])->lazyById(200) as $sale) {
            $emit(new SaleCompleted($tenant, $sale->id, $sale->company_id, $sale->location_id, SaleSnapshot::sale($sale)));
        }

        foreach (SaleVoid::query()->where('status', Records::APPLIED)->whereBetween('voided_at', [$from, $to])->lazyById(200) as $void) {
            $sale = Sale::query()->with(['lines', 'payments'])->find($void->sale_id);

            if ($sale !== null) {
                $emit(new SaleVoided($tenant, $sale->id, $void->id, SaleSnapshot::sale($sale), $void->voided_at->utc()->format('Y-m-d\TH:i:s.u\Z')));
            }
        }

        foreach (Refund::query()->where('status', Records::APPLIED)->whereBetween('refunded_at', [$from, $to])->lazyById(200) as $refund) {
            $emit(new SaleRefunded($tenant, $refund->sale_id, $refund->id, SaleSnapshot::refund($refund)));
        }
    }
}
