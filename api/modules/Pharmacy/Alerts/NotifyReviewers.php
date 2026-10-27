<?php

namespace Modules\Pharmacy\Alerts;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use Modules\Pharmacy\Events\DispenseFlagged;
use Modules\Pharmacy\Events\RecallIssued;
use Modules\Pharmacy\Models\Recall;
use Modules\Pharmacy\PharmacyServiceProvider;

/**
 * PHA-22, PHA-26: a recall was issued (everyone who may see recalls is told)
 * or a sale arrived with something to review (the reviewers where it was
 * sold). Run in the event's tenant, only while the module is active.
 */
class NotifyReviewers
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly PharmacyNotices $notices,
    ) {}

    public function recall(RecallIssued $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive(PharmacyServiceProvider::MODULE)) {
                return;
            }

            $this->notices->send(PharmacyNotices::RECALL, 'pharmacy.recall.view', null, [
                'item_name' => (string) Item::query()->whereKey($event->itemId)->value('name'),
                'batch_number' => $event->batchNumber,
                'reason' => (string) Recall::query()->whereKey($event->recallId)->value('reason'),
                'lots' => (string) count($event->lotIds),
            ], '/pharmacy/recalls/'.rawurlencode($event->recallId));
        });
    }

    public function flagged(DispenseFlagged $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive(PharmacyServiceProvider::MODULE)) {
                return;
            }

            $this->notices->send(PharmacyNotices::FLAGGED, 'pharmacy.dispense.review', $event->locationId, [
                'location_name' => (string) Location::query()->whereKey($event->locationId)->value('name'),
                'receipt_number' => (string) $event->receiptNumber,
                'flags' => implode(', ', array_map(fn (string $flag) => __('pharmacy.flags.'.$flag), $event->flags)),
            ], '/pharmacy/dispenses?review=open');
        });
    }
}
