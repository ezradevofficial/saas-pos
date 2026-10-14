<?php

namespace Modules\Loyalty\Pos;

use App\Core\Rbac\ModuleRegistry;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;

/**
 * LOY-26, RBAC-08: the POS events Loyalty acts on, only while the tenant
 * has both modules active. The events carry what POS stored (SaleSnapshot),
 * so nothing here reads POS tables. Registered by LoyaltyServiceProvider
 * only when the POS module's events exist.
 */
class HandlePosEvents
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly PosSales $sales,
    ) {}

    public function completed(SaleCompleted $event): void
    {
        if ($this->active() && $event->sale !== []) {
            $this->sales->completed($event->sale);
        }
    }

    public function voided(SaleVoided $event): void
    {
        if ($this->active()) {
            $this->sales->voided($event->saleId, $event->voidId);
        }
    }

    public function refunded(SaleRefunded $event): void
    {
        if ($this->active() && $event->refund !== []) {
            $this->sales->refunded($event->refund);
        }
    }

    private function active(): bool
    {
        return $this->modules->isActive('loyalty') && $this->modules->isActive('pos');
    }
}
