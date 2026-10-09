<?php

namespace Modules\POS\Listeners;

use App\Core\Fiscal\FiscalQueue;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;
use Modules\POS\Fiscal\PosFiscalSource;
use Modules\POS\Models\Sale;
use Modules\POS\PosServiceProvider;

/**
 * POS-10: every completed sale, applied refund and applied void goes to
 * the core fiscal queue (once: the queue keeps one submission per
 * document), on the `fiscal` queue so an upload never waits for it. Runs
 * in the event's tenant; nothing is queued while the company has
 * transmission off or the tenant has no POS module.
 */
class QueueFiscalDocument implements ShouldQueue
{
    public function viaQueue(): string
    {
        return (string) config('fiscal.queue');
    }

    public function handle(SaleCompleted|SaleRefunded|SaleVoided $event): void
    {
        app(TenantContext::class)->run($event->tenantId, function () use ($event) {
            if (! app(ModuleRegistry::class)->isActive(PosServiceProvider::MODULE)) {
                return;
            }

            [$type, $id] = match (true) {
                $event instanceof SaleCompleted => ['sale', $event->saleId],
                $event instanceof SaleRefunded => ['refund', $event->refundId],
                default => ['void', $event->voidId],
            };

            $companyId = Sale::query()->whereKey($event->saleId)->value('company_id');

            if ($companyId !== null) {
                app(FiscalQueue::class)->enqueue(PosFiscalSource::KEY, $type, $id, $companyId);
            }
        });
    }
}
