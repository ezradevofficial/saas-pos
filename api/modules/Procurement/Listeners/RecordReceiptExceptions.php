<?php

namespace Modules\Procurement\Listeners;

use App\Core\Tenancy\TenantContext;
use Modules\Inventory\Contracts\GoodsReceiptExceptions;
use Modules\Procurement\Models\ReceiptLine;
use Modules\Procurement\Support\Tx;

/**
 * PRC-14, INV-15 (stage 7B closing review): lines of a posted GRN that
 * Inventory or Stores could not receive (an unknown item or unit, a
 * serial already in stock) are kept on the GRN line
 * (`receive_exceptions`: each module and code once), so the GRN view
 * flags them. Procurement's own data: recorded whatever the modules are.
 */
class RecordReceiptExceptions
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function handle(GoodsReceiptExceptions $event): void
    {
        $this->tenants->run($event->tenantId, fn () => Tx::run(function () use ($event) {
            $codes = collect($event->lines)->groupBy('line_id');
            $lines = ReceiptLine::query()->where('receipt_id', $event->receiptId)->whereKey($codes->keys()->all())->lockForUpdate()->get();

            foreach ($lines as $line) {
                $kept = collect($line->receive_exceptions ?? []);

                foreach ($codes->get($line->id, []) as $exception) {
                    $entry = ['module' => $event->module, 'code' => (string) $exception['code']];

                    if (! $kept->contains($entry)) {
                        $kept->push($entry);
                    }
                }

                $line->fill(['receive_exceptions' => $kept->values()->all()])->save();
            }
        }));
    }
}
