<?php

namespace Modules\Pharmacy\Stock;

use App\Core\Http\ApiException;
use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Contracts\GoodsReceived;
use Modules\Pharmacy\Items\Profiles;
use Modules\Pharmacy\Models\LotMovement;
use Modules\Pharmacy\Models\Receipt;
use Modules\Pharmacy\Models\RegisterEntry;
use Modules\Pharmacy\PharmacyServiceProvider;
use Modules\Pharmacy\Register\ControlledRegister;
use Modules\Pharmacy\Support\Places;
use Modules\Pharmacy\Support\Units;

/**
 * PHA-06: goods received by another module (Procurement's GRN, through
 * Inventory's `GoodsReceived` contract) enter the lots of tracked items
 * that name a batch and an expiry. Never refused (the goods are there): a
 * line without a batch or expiry, for an untracked item, or whose batch
 * has a different expiry on file is skipped and logged. The receipt
 * Pharmacy itself raised for a back-office receipt is recognised and
 * ignored. Once per receipt line (the ledger).
 */
class RecordGoodsReceived implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly Lots $lots,
        private readonly Profiles $profiles,
        private readonly Units $units,
        private readonly Places $places,
        private readonly ControlledRegister $register,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function handle(GoodsReceived $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive(PharmacyServiceProvider::MODULE) || Receipt::query()->whereKey($event->receiptId)->exists()) {
                return;
            }

            $place = $this->places->of($event->locationId);

            if ($place === null || $place['company_id'] !== $event->companyId) {
                Log::warning('pharmacy: goods received at a location of another company or unknown', ['receipt' => $event->receiptId]);

                return;
            }

            $at = CarbonImmutable::parse($event->receivedAt);
            $profiles = $this->profiles->many(array_column($event->lines, 'item_id'));

            DB::connection(TenantContext::CONNECTION)->transaction(function () use ($event, $at, $profiles, $place) {
                foreach ($event->lines as $line) {
                    $profile = $profiles->get($line['item_id']);
                    $item = $profile === null ? null : Item::query()->find($line['item_id']);

                    if ($profile === null || ! $profile->batch_tracked || $item === null || empty($line['batch_number']) || empty($line['expires_on']) || ! BigDecimal::of($line['qty'])->isPositive()) {
                        if ($profile?->batch_tracked) {
                            Log::warning('pharmacy: goods received line without batch or expiry skipped', ['receipt' => $event->receiptId, 'line' => $line['line_id']]);
                        }

                        continue;
                    }

                    try {
                        $lot = $this->lots->ensure($place['company_id'], $item->id, (string) $line['batch_number'], (string) $line['expires_on']);
                    } catch (ApiException $e) {
                        Log::warning('pharmacy: goods received line skipped', ['receipt' => $event->receiptId, 'line' => $line['line_id'], 'error' => $e->getMessage()]);

                        continue;
                    }

                    $base = $this->units->toBase($item, $line['uom_id'] ?? null, (string) $line['qty']);
                    $this->lots->move($lot, $event->locationId, LotMovement::RECEIPT, $base, 'procurement.goods_receipt', $event->receiptId, (string) $line['line_id'], $at, userId: $event->userId);

                    if ($profile->isControlled()) {
                        $this->register->append($place['company_id'], $event->locationId, $item->id, RegisterEntry::RECEIPT, $base, 'procurement.goods_receipt', $event->receiptId, (string) $line['line_id'], $lot, null, null, $event->userId, null, $at);
                    }
                }
            });
        });
    }
}
