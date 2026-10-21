<?php

namespace Modules\FixedAssets\Listeners;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\FixedAssets\FixedAssetsServiceProvider;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Support\FaError;
use Modules\FixedAssets\Support\History;
use Modules\Procurement\Events\GoodsReceiptCancelled;

/**
 * FA-21 (review #14): a cancelled GRN cancels the draft assets made from
 * it (archived, with the reason; TEN-06). Procurement cancels only draft
 * GRNs, which never made drafts, so this is a guard: it touches only
 * drafts, never an activated asset. Queued after Procurement's commit, in
 * the event's tenant, while Fixed assets is active.
 */
class CancelDraftsOfReceipt implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly History $history,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function handle(GoodsReceiptCancelled $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive(FixedAssetsServiceProvider::MODULE)) {
                return;
            }

            FaError::transaction(function () use ($event) {
                $reason = __('fixed_assets.sources.receipt_cancelled', ['number' => $event->reference ?? '-']);
                $drafts = Asset::query()->where('source', Asset::FROM_RECEIPT)->where('source_id', $event->receiptId)
                    ->where('status', Asset::DRAFT)->whereNull('archived_at')->lockForUpdate()->get();

                foreach ($drafts as $asset) {
                    $asset->fill(['archived_at' => CarbonImmutable::now(), 'archive_reason' => $reason])->save();
                    $this->history->record($asset, 'cancelled', null, note: $reason);
                }
            });
        });
    }
}
