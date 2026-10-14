<?php

namespace Modules\Inventory\Listeners;

use App\Core\Currency\Converter;
use App\Core\Currency\Money;
use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Contracts\GoodsReceived;
use Modules\Inventory\InventoryServiceProvider;
use Modules\Inventory\Models\Batch;
use Modules\Inventory\Models\ItemSetting;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Places;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockInput;
use Modules\Inventory\Stock\StockLedger;
use Modules\Inventory\Stock\Units;

/**
 * INV-15: goods received by another module (Procurement's GRN) enter stock
 * as `receipt` movements at their landed cost, in the company's base
 * currency (a cost in another currency converted at the company's rate on
 * the receipt date, CUR-04), with their batches and serials. Never refused
 * (the goods are there): a line naming an unknown or non-stock item or a
 * unit the item does not have is skipped and logged; a serial already in
 * stock is left out of the line and flagged. Once per receipt (the ledger).
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
        private readonly StockLedger $ledger,
        private readonly StockInput $input,
        private readonly Units $units,
        private readonly Places $places,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function handle(GoodsReceived $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive(InventoryServiceProvider::MODULE)) {
                return;
            }

            $place = $this->places->of($event->locationId);

            if ($place === null || $place['company_id'] !== $event->companyId) {
                Log::warning('inventory: goods received at a location of another company or unknown', ['receipt' => $event->receiptId]);

                return;
            }

            $company = Company::query()->findOrFail($event->companyId);
            $at = CarbonImmutable::parse($event->receivedAt);

            StockInput::transaction(function () use ($event, $company, $at) {
                $lines = [];

                foreach ($event->lines as $line) {
                    $item = Item::query()->whereKey($line['item_id'])->where('type', 'stock')->first();
                    $factor = $item === null ? null : $this->units->factor($item, $line['uom_id'] ?? null);

                    if ($item === null || $factor === null || ! BigDecimal::of($line['qty'])->isPositive()) {
                        Log::warning('inventory: goods received line skipped', ['receipt' => $event->receiptId, 'line' => $line['line_id']]);

                        continue;
                    }

                    $tracking = ItemSetting::query()->where('item_id', $item->id)->value('tracking') ?? ItemSetting::NONE;
                    $flags = [];
                    $serialIds = [];

                    if ($tracking === ItemSetting::SERIAL) {
                        foreach ($line['serials'] ?? [] as $serial) {
                            try {
                                array_push($serialIds, ...$this->input->serialIds($item->id, [(string) $serial], true, $event->locationId));
                            } catch (\Throwable) {
                                $flags[] = ['code' => 'serial_exists'];
                            }
                        }

                        if (($line['serials'] ?? []) === []) {
                            $flags[] = ['code' => 'serials_missing'];
                        }
                    }

                    $lines[] = new PostingLine(
                        $item->id, $event->locationId, Movement::RECEIPT, $this->units->toBase(BigDecimal::of($line['qty']), $factor),
                        sourceLineId: $line['line_id'],
                        batchId: $tracking === ItemSetting::BATCH && ! empty($line['batch_number']) ? $this->batch($item->id, (string) $line['batch_number'], $line['expires_on'] ?? null) : null,
                        uomId: $line['uom_id'] ?? $item->base_uom_id,
                        uomQty: Qty::of($line['qty']),
                        cost: $this->cost($line, $company, $at),
                        serialIds: $serialIds,
                        flags: $tracking === ItemSetting::BATCH && empty($line['batch_number']) ? [...$flags, ['code' => 'batch_missing']] : $flags,
                        setsLastCost: true,
                        landed: BigInteger::of((string) ($line['landed_minor'] ?? '0')),
                    );
                }

                $this->ledger->post(new Posting($event->companyId, 'procurement.goods_receipt', $event->receiptId, $at, $event->userId, $lines, manual: false));
            });
        });
    }

    /** The line's cost in the company's base currency (CUR-04). */
    private function cost(array $line, Company $company, CarbonImmutable $at): BigInteger
    {
        $cost = Money::ofMinor((string) $line['cost_minor'], (string) $line['currency']);

        if ($cost->currency() === $company->base_currency) {
            return BigInteger::of($cost->minor());
        }

        return BigInteger::of(app(Converter::class)->toBase($cost, $company, $at)['base']->minor());
    }

    private function batch(string $itemId, string $number, ?string $expiresOn): string
    {
        $existing = Batch::query()->where('item_id', $itemId)->whereRaw('lower(batch_number) = lower(?)', [$number])->first();

        return $existing?->id ?? Batch::create(['item_id' => $itemId, 'batch_number' => $number, 'expires_on' => $expiresOn])->id;
    }
}
