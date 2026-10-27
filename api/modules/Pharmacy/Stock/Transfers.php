<?php

namespace Modules\Pharmacy\Stock;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\CompanyClock;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Pharmacy\Items\Profiles;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\LotMovement;
use Modules\Pharmacy\Models\RegisterEntry;
use Modules\Pharmacy\Models\Settings;
use Modules\Pharmacy\Register\ControlledRegister;
use Modules\Pharmacy\Support\PharmacyError;
use Modules\Pharmacy\Support\Places;
use Modules\Pharmacy\Support\Qty;
use Modules\Pharmacy\Support\Units;

/**
 * PHA-13: lots moved between two locations of one company in one step
 * (`transfer_out` at the source, `transfer_in` at the destination). A line
 * names its lots, or leaves them out to take them first-expiry-first-out
 * (PHA-07). Expired, quarantined and recalled lots are refused, and so is a
 * quantity the source does not hold (`insufficient_stock`). Controlled items
 * write a register entry at both ends.
 *
 * Also moves lots after Inventory's own transfer was received
 * (moveForInventoryTransfer): first-expiry-first-out, nothing refused.
 */
class Transfers
{
    public function __construct(
        private readonly Lots $lots,
        private readonly Profiles $profiles,
        private readonly Units $units,
        private readonly Places $places,
        private readonly ControlledRegister $register,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{from_location_id: string, to_location_id: string, note?: ?string, lines: list<array{item_id: string, uom_id?: ?string, qty: string, lots?: list<array{lot_id: string, qty: string}>}>}  $data
     * @return array{id: string, from_location_id: string, to_location_id: string, lines: list<array{item_id: string, lot_id: string, qty: string}>}
     */
    public function move(array $data, User $by): array
    {
        $from = $this->places->require($data['from_location_id'], 'from_location_id');
        $to = $this->places->require($data['to_location_id'], 'to_location_id');

        if ($data['from_location_id'] === $data['to_location_id']) {
            throw PharmacyError::make(422, 'transfer_same_location', field: 'to_location_id');
        }

        if ($from['company_id'] !== $to['company_id']) {
            throw PharmacyError::make(422, 'transfer_cross_company', field: 'to_location_id');
        }

        $settings = Settings::for($from['company_id']);
        $today = CompanyClock::today($from['company_id'], $from['branch_id']);
        $items = Item::query()->whereKey(array_column($data['lines'], 'item_id'))->get()->keyBy('id');
        $profiles = $this->profiles->many(array_column($data['lines'], 'item_id'));
        $id = (string) Str::uuid7();
        $at = CarbonImmutable::now();

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data, $by, $from, $settings, $today, $items, $profiles, $id, $at) {
            $done = [];

            foreach ($data['lines'] as $index => $line) {
                $item = $items->get($line['item_id']);
                $field = "lines.{$index}";

                if ($item === null || ! ($profiles->get($item->id)?->batch_tracked)) {
                    throw PharmacyError::make(422, 'item_not_tracked', field: "{$field}.item_id");
                }

                $want = $this->units->toBase($item, $line['uom_id'] ?? null, (string) $line['qty']);
                $picks = $this->picks($line, $item->id, $data['from_location_id'], $settings, $today, $want, $field);
                $lineId = (string) Str::uuid7();

                foreach ($picks as $pick) {
                    $out = $this->lots->move($pick['lot'], $data['from_location_id'], LotMovement::TRANSFER_OUT, $pick['qty']->negated(), 'pharmacy.transfer', $id, $lineId, $at, userId: $by->id, note: $data['note'] ?? null);
                    $this->lots->move($pick['lot'], $data['to_location_id'], LotMovement::TRANSFER_IN, $pick['qty'], 'pharmacy.transfer', $id, $lineId, $at, userId: $by->id, note: $data['note'] ?? null);

                    if ($out !== null && $profiles->get($item->id)->isControlled()) {
                        $this->register->append($from['company_id'], $data['from_location_id'], $item->id, RegisterEntry::TRANSFER_OUT, $pick['qty']->negated(), 'pharmacy.transfer', $id, $lineId, $pick['lot'], null, null, $by->id, $data['note'] ?? null, $at);
                        $this->register->append($from['company_id'], $data['to_location_id'], $item->id, RegisterEntry::TRANSFER_IN, $pick['qty'], 'pharmacy.transfer', $id, $lineId, $pick['lot'], null, null, $by->id, $data['note'] ?? null, $at);
                    }

                    $done[] = ['item_id' => $item->id, 'lot_id' => $pick['lot']->id, 'qty' => Qty::show($pick['qty'])];
                }
            }

            $this->auditor->record('pharmacy.transfer.create', null, null, ['transfer_id' => $id, 'from_location_id' => $data['from_location_id'], 'to_location_id' => $data['to_location_id'], 'lines' => $done]);

            return ['id' => $id, 'from_location_id' => $data['from_location_id'], 'to_location_id' => $data['to_location_id'], 'lines' => $done];
        });
    }

    /**
     * The lots and quantities of one line.
     *
     * @param  array<string, mixed>  $line
     * @return list<array{lot: Lot, qty: BigDecimal}>
     */
    private function picks(array $line, string $itemId, string $locationId, Settings $settings, string $today, BigDecimal $want, string $field): array
    {
        $picks = [];

        if (! empty($line['lots'])) {
            $total = Qty::zero();

            foreach ($line['lots'] as $i => $pick) {
                $lot = Lot::query()->find($pick['lot_id']);

                if ($lot === null || $lot->item_id !== $itemId) {
                    throw PharmacyError::make(422, 'lot_unknown', field: "{$field}.lots.{$i}.lot_id");
                }

                $verdict = $this->lots->check($lot, $settings, $today);

                if ($verdict['refuse'] !== null) {
                    throw PharmacyError::make(422, $verdict['refuse'], ['batch' => $lot->batch_number], "{$field}.lots.{$i}.lot_id");
                }

                $qty = Qty::of((string) $pick['qty']);

                if ($this->lots->onHand($lot, $locationId)->isLessThan($qty)) {
                    throw PharmacyError::make(422, 'insufficient_stock', ['batch' => $lot->batch_number], "{$field}.lots.{$i}.qty");
                }

                $picks[] = ['lot' => $lot, 'qty' => $qty];
                $total = $total->plus($qty);
            }

            if (! $total->isEqualTo($want)) {
                throw PharmacyError::make(422, 'lots_do_not_add_up', field: "{$field}.qty");
            }

            return $picks;
        }

        $fefo = $this->lots->suggest($itemId, $locationId, $settings, $today, $want);

        if (BigDecimal::of($fefo['shortfall'])->isPositive()) {
            throw PharmacyError::make(422, 'insufficient_stock', ['batch' => '-'], "{$field}.qty");
        }

        foreach ($fefo['lots'] as $row) {
            if (BigDecimal::of($row['take'])->isPositive()) {
                $picks[] = ['lot' => $row['lot'], 'qty' => Qty::of($row['take'])];
            }
        }

        return $picks;
    }

    /**
     * PHA-13: Inventory's own transfer was received: take what was sent
     * from the source's lots first-expiry-first-out and put what arrived
     * into the destination (what was lost in transit is written off the
     * source and flagged). Never refused; once per transfer line.
     *
     * @param  array{transfer_id: string, company_id: string, from_location_id: string, to_location_id: string, received_at: ?string, reference: string, lines: list<array{line_id: string, item_id: string, qty_sent: string, qty_received: string}>}  $transfer
     */
    public function moveForInventoryTransfer(array $transfer, ?string $userId = null): void
    {
        $settings = Settings::for($transfer['company_id']);
        $from = $this->places->of($transfer['from_location_id']);
        $today = CompanyClock::today($transfer['company_id'], $from['branch_id'] ?? null);
        $at = $transfer['received_at'] === null ? CarbonImmutable::now() : CarbonImmutable::parse($transfer['received_at']);
        $profiles = $this->profiles->many(array_column($transfer['lines'], 'item_id'));

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($transfer, $settings, $today, $at, $profiles, $userId) {
            foreach ($transfer['lines'] as $line) {
                $profile = $profiles->get($line['item_id']);

                if ($profile === null || ! $profile->batch_tracked) {
                    continue;
                }

                $sent = Qty::of($line['qty_sent']);
                $arrived = Qty::of($line['qty_received']);
                $fefo = $this->lots->suggest($line['item_id'], $transfer['from_location_id'], $settings, $today, $sent);
                $left = $arrived;

                foreach ($fefo['lots'] as $row) {
                    $take = Qty::of($row['take']);

                    if (! $take->isPositive()) {
                        continue;
                    }

                    $in = Qty::min($take, $left);
                    $lost = $take->minus($in);
                    $source = 'inventory.transfer';
                    $this->lots->move($row['lot'], $transfer['from_location_id'], LotMovement::TRANSFER_OUT, $take->negated(), $source, $transfer['transfer_id'], $line['line_id'], $at, userId: $userId);

                    if ($in->isPositive()) {
                        $this->lots->move($row['lot'], $transfer['to_location_id'], LotMovement::TRANSFER_IN, $in, $source, $transfer['transfer_id'], $line['line_id'], $at, userId: $userId);
                        $left = $left->minus($in);
                    }

                    if ($profile->isControlled()) {
                        $this->register->append($transfer['company_id'], $transfer['from_location_id'], $line['item_id'], RegisterEntry::TRANSFER_OUT, $take->negated(), $source, $transfer['transfer_id'], $line['line_id'], $row['lot'], null, null, $userId, $lost->isPositive() ? 'transfer_loss' : null, $at);

                        if ($in->isPositive()) {
                            $this->register->append($transfer['company_id'], $transfer['to_location_id'], $line['item_id'], RegisterEntry::TRANSFER_IN, $in, $source, $transfer['transfer_id'], $line['line_id'], $row['lot'], null, null, $userId, null, $at);
                        }
                    }
                }
            }
        });
    }
}
