<?php

namespace Modules\Accounting\Listeners;

use App\Core\Fiscal\FiscalQueue;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Accounting\AccountingServiceProvider;
use Modules\Accounting\Events\DocumentPosted;
use Modules\Accounting\Fiscal\AccountingFiscalSource;

/**
 * ACC-19: every posted customer invoice and credit note goes to the core
 * fiscal queue once (the queue keeps one submission per document), on the
 * `fiscal` queue; nothing is queued while the company has transmission
 * off (FiscalQueue) or the tenant has no Accounting module.
 */
class QueueFiscalDocument implements ShouldQueue
{
    public function viaQueue(): string
    {
        return (string) config('fiscal.queue');
    }

    public function handle(DocumentPosted $event): void
    {
        if (! in_array($event->kind, ['customer_invoice', 'customer_credit'], true)) {
            return;
        }

        app(TenantContext::class)->run($event->tenantId, function () use ($event) {
            if (app(ModuleRegistry::class)->isActive(AccountingServiceProvider::MODULE)) {
                app(FiscalQueue::class)->enqueue(AccountingFiscalSource::KEY, AccountingFiscalSource::typeOf($event->kind), $event->documentId, $event->companyId);
            }
        });
    }
}
