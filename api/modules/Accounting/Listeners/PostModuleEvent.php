<?php

namespace Modules\Accounting\Listeners;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Accounting\Integrations\Integrations;
use Modules\Accounting\Integrations\InventorySources;
use Modules\Inventory\Events\StockMoved;
use Modules\Loyalty\Events\LoyaltyLedgerEvent;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;
use Modules\Stores\Events\StoreConsumptionValued;

/**
 * ACC-25 to ACC-32, RBAC-08: other modules' events reach the ledger here,
 * queued after the raising transaction commits and run in the event's
 * tenant, so posting never slows or fails a till's upload or another
 * module's work (NFR-04). A failed attempt is retried; a source that
 * cannot post is kept as a posting exception, not retried here.
 *
 * A job is queued only while Accounting and the raising module are both
 * active, and never for a stock posting that posts nothing (Stores' own
 * moves, transfers within the company, no movements). Once queued, the job
 * posts while Accounting is active, whatever the raising module is by
 * then: the event proves the module was on when it happened (stage 7B
 * closing review, decision; owner may change).
 */
class PostModuleEvent implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Integrations $integrations,
        private readonly ModuleRegistry $modules,
        private readonly InventorySources $inventory,
    ) {}

    /** Review #9: nothing is queued for a tenant without Accounting or the raising module (checked in the event's tenant). */
    public function shouldQueue(object $event): bool
    {
        // Stores' own moves (stores.*), transfers and empty postings change no ledger value (ACC-27, ACC-31).
        if ($event instanceof StockMoved && $this->inventory->stockMoved($event) === null) {
            return false;
        }

        $check = function () use ($event): bool {
            $raisedBy = self::raisedBy($event);

            return $this->modules->isActive(AccountingServiceProvider::MODULE) && ($raisedBy === null || $this->modules->isActive($raisedBy));
        };

        // Stage 7B performance review: the upload already runs in the event's tenant.
        return $this->tenants->id() === $event->tenantId ? $check() : $this->tenants->run($event->tenantId, $check);
    }

    public function viaQueue(): string
    {
        return 'default';
    }

    public function handle(object $event): void
    {
        $this->tenants->run($event->tenantId, fn () => $this->integrations->handle($event));
    }

    /** The module that raises $event. */
    public static function raisedBy(object $event): ?string
    {
        return match (true) {
            $event instanceof SaleCompleted, $event instanceof SaleVoided, $event instanceof SaleRefunded => 'pos',
            $event instanceof StockMoved => 'inventory',
            $event instanceof LoyaltyLedgerEvent => 'loyalty',
            $event instanceof StoreConsumptionValued => 'stores',
            str_starts_with($event::class, 'Modules\\Procurement\\') => 'procurement',
            default => null,
        };
    }
}
