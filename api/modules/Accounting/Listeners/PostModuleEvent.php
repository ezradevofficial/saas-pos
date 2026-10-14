<?php

namespace Modules\Accounting\Listeners;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Accounting\Integrations\Integrations;

/**
 * ACC-25 to ACC-28, RBAC-08: POS, Inventory and Loyalty events reach the
 * ledger here, queued after the raising transaction commits and run in
 * the event's tenant, so posting never slows or fails a till's upload or
 * another module's work (NFR-04). Integrations acts only while Accounting
 * and the raising module are both active. A failed attempt is retried;
 * a source that cannot post is kept as a posting exception, not retried
 * here.
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
    ) {}

    /** Review #9: nothing is queued for a tenant without Accounting (checked in the event's tenant). */
    public function shouldQueue(object $event): bool
    {
        return $this->tenants->run($event->tenantId, fn () => $this->modules->isActive(AccountingServiceProvider::MODULE));
    }

    public function viaQueue(): string
    {
        return 'default';
    }

    public function handle(object $event): void
    {
        $this->tenants->run($event->tenantId, fn () => $this->integrations->handle($event));
    }
}
