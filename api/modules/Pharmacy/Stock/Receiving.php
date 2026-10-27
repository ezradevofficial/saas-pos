<?php

namespace Modules\Pharmacy\Stock;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\CompanyClock;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Contracts\GoodsReceived;
use Modules\Pharmacy\Events\LotReceived;
use Modules\Pharmacy\Items\Profiles;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\LotMovement;
use Modules\Pharmacy\Models\Receipt;
use Modules\Pharmacy\Models\ReceiptLine;
use Modules\Pharmacy\Models\RegisterEntry;
use Modules\Pharmacy\Register\ControlledRegister;
use Modules\Pharmacy\Support\PharmacyError;
use Modules\Pharmacy\Support\Places;
use Modules\Pharmacy\Support\Qty;
use Modules\Pharmacy\Support\Units;

/**
 * PHA-06: receiving lots in the back office.
 *
 * Each line names an item with a pharmacy profile that is tracked by lot, a
 * batch number, an expiry (not already past in the company's day), an
 * optional manufacture date, a quantity in any unit of the item and its
 * unit cost. The lot is made on first use (a later receipt of the same
 * batch must name the same expiry), the stock enters the location as a
 * `receipt` movement and, for a controlled item, the register. After
 * commit, Inventory's `GoodsReceived` is raised with the batch and expiry,
 * so Inventory posts the receipt and its cost when it is active.
 */
class Receiving
{
    public function __construct(
        private readonly Lots $lots,
        private readonly Profiles $profiles,
        private readonly Units $units,
        private readonly Places $places,
        private readonly ControlledRegister $register,
        private readonly Auditor $auditor,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  array{location_id: string, supplier_id?: ?string, reference?: ?string, received_at?: ?string, note?: ?string, lines: list<array<string, mixed>>}  $data
     */
    public function receive(array $data, User $by): Receipt
    {
        $place = $this->places->require($data['location_id']);
        $today = CompanyClock::today($place['company_id'], $place['branch_id']);
        $at = isset($data['received_at']) ? CarbonImmutable::parse($data['received_at']) : CarbonImmutable::now();
        $items = Item::query()->whereKey(array_column($data['lines'], 'item_id'))->get()->keyBy('id');
        $profiles = $this->profiles->many(array_column($data['lines'], 'item_id'));

        foreach ($data['lines'] as $index => $line) {
            $item = $items->get($line['item_id']);
            $field = "lines.{$index}";

            if ($item === null || $item->type !== 'stock') {
                throw PharmacyError::make(422, 'item_not_stock', field: "{$field}.item_id");
            }

            if (! ($profiles->get($item->id)?->batch_tracked)) {
                throw PharmacyError::make(422, 'item_not_tracked', field: "{$field}.item_id");
            }

            if ($this->units->factor($item, $line['uom_id'] ?? null) === null) {
                throw PharmacyError::make(422, 'uom_not_on_item', field: "{$field}.uom_id");
            }

            if (CarbonImmutable::parse($line['expires_on'])->toDateString() < $today) {
                throw PharmacyError::make(422, 'lot_expired', ['batch' => $line['batch_number']], "{$field}.expires_on");
            }
        }

        $receipt = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data, $by, $place, $at, $items) {
            $receipt = Receipt::create([
                'company_id' => $place['company_id'],
                'branch_id' => $place['branch_id'],
                'location_id' => $data['location_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'received_at' => $at,
                'note' => $data['note'] ?? null,
                'created_by' => $by->id,
            ]);

            foreach ($data['lines'] as $index => $line) {
                $item = $items->get($line['item_id']);
                $base = $this->units->toBase($item, $line['uom_id'] ?? null, (string) $line['qty']);
                $lot = $this->lots->ensure($place['company_id'], $item->id, $line['batch_number'], $line['expires_on'], $line['manufactured_on'] ?? null, $data['supplier_id'] ?? null);
                $row = ReceiptLine::create([
                    'receipt_id' => $receipt->id,
                    'line_no' => $index + 1,
                    'item_id' => $item->id,
                    'lot_id' => $lot->id,
                    'uom_id' => $line['uom_id'] ?? $item->base_uom_id,
                    'entered_qty' => (string) Qty::of((string) $line['qty']),
                    'qty' => (string) $base,
                    'unit_cost_minor' => $line['unit_cost_minor'] ?? null,
                    'currency' => $line['currency'] ?? null,
                ]);
                $this->lots->move($lot, $data['location_id'], LotMovement::RECEIPT, $base, 'pharmacy.receipt', $receipt->id, $row->id, $at, userId: $by->id);

                if ($this->profiles->of($item->id)?->isControlled()) {
                    $this->register->append($place['company_id'], $data['location_id'], $item->id, RegisterEntry::RECEIPT, $base, 'pharmacy.receipt', $receipt->id, $row->id, $lot, null, null, $by->id, null, $at);
                }

                LotReceived::dispatch($receipt->tenant_id, $lot->id, $place['company_id'], $data['location_id'], $item->id, (string) $base);
            }

            $this->auditor->record('pharmacy.receipt.create', $receipt, null, ['location_id' => $data['location_id'], 'lines' => count($data['lines']), 'reference' => $receipt->reference]);

            return $receipt->refresh()->load('lines');
        });

        $this->toInventory($receipt, $items, $by, $data['lines']);

        return $receipt;
    }

    /**
     * PHA-06: Inventory posts what was received, at the typed cost, with the batch and expiry
     * (its own listener checks the module is active for the tenant).
     *
     * @param  Collection<string, Item>  $items
     * @param  list<array<string, mixed>>  $lines  as typed
     */
    private function toInventory(Receipt $receipt, $items, User $by, array $lines): void
    {
        $sent = [];

        foreach ($receipt->lines as $index => $row) {
            $typed = $lines[$index];

            if ($row->unit_cost_minor === null) {
                continue;
            }

            $cost = BigDecimal::of((string) $row->unit_cost_minor)->multipliedBy((string) $row->entered_qty)->toScale(0, RoundingMode::HalfUp);
            $sent[] = [
                'line_id' => $row->id,
                'item_id' => $row->item_id,
                'uom_id' => $row->uom_id,
                'qty' => (string) $row->entered_qty,
                'cost_minor' => (string) $cost,
                'currency' => (string) $row->currency,
                'batch_number' => $typed['batch_number'],
                'expires_on' => CarbonImmutable::parse($typed['expires_on'])->toDateString(),
            ];
        }

        if ($sent !== []) {
            GoodsReceived::dispatch($receipt->tenant_id, $receipt->id, $receipt->company_id, $receipt->location_id, $receipt->received_at->toIso8601String(), $receipt->reference ?? Str::of($receipt->id)->substr(0, 8)->toString(), $sent, $by->id);
        }
    }
}
