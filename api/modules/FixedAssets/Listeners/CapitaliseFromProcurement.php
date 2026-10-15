<?php

namespace Modules\FixedAssets\Listeners;

use App\Core\Currency\Money;
use App\Core\Http\ApiException;
use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\FixedAssets\Assets\Assets;
use Modules\FixedAssets\FixedAssetsServiceProvider;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\ItemCategory;
use Modules\FixedAssets\Support\Decimals;
use Modules\Procurement\Events\GoodsReceiptPosted;
use Modules\Procurement\Events\SupplierInvoiceApproved;

/**
 * FA-21: goods received, or a supplier invoice approved, for items flagged
 * as assets become draft assets for a person to review and activate.
 * Queued after Procurement's transaction commits and run in the event's
 * tenant, only while Fixed assets is active. One draft per whole unit
 * (at most 50 a line; otherwise one for the line), the line's base value
 * (with its landed cost) split by largest remainder, its serials one per
 * draft. A line makes drafts once (a unique index on the source line):
 * a retried or replayed event creates nothing new. An invoice line whose
 * PO line already gave drafts from a GRN is skipped.
 */
class CapitaliseFromProcurement implements ShouldQueue
{
    public const MAX_UNITS = 50;

    public bool $afterCommit = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ModuleRegistry $modules,
        private readonly Assets $assets,
    ) {}

    public function viaQueue(): string
    {
        return 'default';
    }

    public function shouldQueue(GoodsReceiptPosted|SupplierInvoiceApproved $event): bool
    {
        $check = fn () => $this->modules->isActive(FixedAssetsServiceProvider::MODULE);

        return $this->tenants->id() === $event->tenantId ? $check() : $this->tenants->run($event->tenantId, $check);
    }

    public function handle(GoodsReceiptPosted|SupplierInvoiceApproved $event): void
    {
        $this->tenants->run($event->tenantId, function () use ($event) {
            if (! $this->modules->isActive(FixedAssetsServiceProvider::MODULE)) {
                return;
            }

            $company = Company::query()->find($event->companyId);

            if ($company === null) {
                return;
            }

            $flagged = ItemCategory::query()->where('company_id', $company->id)->whereNull('archived_at')->pluck('category_id', 'item_id')->all();

            if ($flagged === []) {
                return;
            }

            $date = CarbonImmutable::parse($event->date)->setTimezone($company->timezone ?: 'UTC')->toDateString();

            if ($event instanceof GoodsReceiptPosted) {
                foreach ($event->lines as $line) {
                    $this->line($company, $flagged, $line, (int) $line['base_value_minor'] + (int) ($line['landed_minor'] ?? 0), (string) $line['location_id'],
                        Asset::FROM_RECEIPT, $event->receiptId, $date, $event->dimensions['party_id'] ?? null, $event->reference);
                }

                return;
            }

            $location = $event->document['location_id'] ?? null;

            foreach ((array) ($event->document['lines'] ?? []) as $line) {
                if (! isset($line['line_id'], $line['order_line_id']) || $location === null
                    || Asset::query()->where('order_line_id', $line['order_line_id'])->where('source', Asset::FROM_RECEIPT)->exists()) {
                    continue;
                }

                $this->line($company, $flagged, $line, (int) $line['base_net_minor'], (string) $location, Asset::FROM_INVOICE, $event->invoiceId, $date,
                    $event->document['party_id'] ?? null, (string) ($event->document['supplier_reference'] ?? $event->reference));
            }
        });
    }

    /**
     * @param  array<string, string>  $flagged  item id => category id
     * @param  array<string, mixed>  $line
     */
    private function line(Company $company, array $flagged, array $line, int $baseValue, string $locationId, string $source, string $sourceId,
        string $date, ?string $partyId, ?string $reference): void
    {
        $itemId = $line['item_id'] ?? null;

        if ($itemId === null || ! isset($flagged[$itemId]) || $baseValue <= 0) {
            return;
        }

        $qty = BigDecimal::of((string) ($line['qty'] ?? '1'));
        $units = Decimals::whole($qty) ? $qty->toScale(0)->toInt() : 1;
        $units = $units >= 1 && $units <= self::MAX_UNITS ? $units : 1;
        $parts = Money::ofMinor($baseValue, (string) $company->base_currency)->allocate(array_fill(0, $units, 1));
        $serials = array_values((array) ($line['serials'] ?? []));
        $name = (string) ($line['description'] ?: Item::query()->whereKey($itemId)->value('name'));

        foreach ($parts as $index => $part) {
            $unit = $index + 1;

            if (Asset::query()->where('source', $source)->where('source_line_id', $line['line_id'])->where('source_unit', $unit)->exists()) {
                continue;
            }

            try {
                $this->assets->create([
                    'location_id' => $locationId,
                    'category_id' => $flagged[$itemId],
                    'name' => mb_substr($name, 0, 200),
                    'acquired_on' => $date,
                    'cost_minor' => (int) $part->minor(),
                    'currency' => (string) $company->base_currency,
                    'supplier_id' => $partyId,
                    'supplier_reference' => $reference === null ? null : mb_substr($reference, 0, 120),
                    'serial_number' => isset($serials[$index]) ? mb_substr((string) $serials[$index], 0, 120) : null,
                ], null, [
                    'source' => $source,
                    'source_id' => $sourceId,
                    'source_line_id' => $line['line_id'],
                    'source_unit' => $unit,
                    'order_line_id' => $line['order_line_id'] ?? null,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Made by a concurrent run of the same event.
            } catch (ApiException $e) {
                // A location or category no longer fit (archived): the line is left for a person to register by hand.
                report($e);
            }
        }
    }
}
