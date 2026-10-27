<?php

namespace Modules\Pharmacy\Sales;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\CompanyClock;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Pharmacy\Events\DispenseFlagged;
use Modules\Pharmacy\Items\Profiles;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Models\ItemProfile;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\LotMovement;
use Modules\Pharmacy\Models\Prescription;
use Modules\Pharmacy\Models\RegisterEntry;
use Modules\Pharmacy\Models\Settings;
use Modules\Pharmacy\Prescriptions\Prescriptions;
use Modules\Pharmacy\Register\ControlledRegister;
use Modules\Pharmacy\Stock\Lots;
use Modules\Pharmacy\Support\Qty;
use Modules\Pharmacy\Support\Units;
use Modules\POS\Events\SaleStockLines;
use Modules\POS\Sync\OverrideVerifier;
use Modules\POS\Sync\Rejection;
use Throwable;

/**
 * PHA-16, PHA-17, PHA-26, PHA-27: what a POS sale does to Pharmacy, once
 * the sale is stored (the device already let the customer go, so nothing
 * here refuses it: the device wins for completed sales, NFR-04).
 *
 * - completed(): each line of a profiled item takes its lots (the till's
 *   choice from `extensions.pharmacy.lines`, else first-expiry-first-out,
 *   flagged `batch_not_chosen`), is re-checked against the lots as the
 *   server holds them, and, when it needs a prescription or a pharmacist
 *   or is controlled, gets a dispense record and a register entry. The
 *   sale's prescription (made once under the till's id) and its pharmacist
 *   approval (the cashier's own right, else a signed override redeemed for
 *   the sale) are verified here.
 * - voided(), refunded(): the lots come back (a void everything not yet
 *   refunded; a refund the refunded quantity, first-sold lots first).
 *
 * Everything about one sale runs under one advisory lock, in this order
 * whatever the order the events arrive in: a void or refund handled before
 * its sale finds nothing to bring back, and the sale, handled later, reads
 * the sale's state (a voided sale takes nothing; refunds already applied
 * are brought back right after it). Every movement is once per source line,
 * so a redelivered event changes nothing.
 */
class Dispensing
{
    /** Flags that put a dispense in the review list (PHA-26). */
    public const REVIEW_FLAGS = [
        'lot_expired', 'lot_quarantined', 'lot_recalled', 'lot_too_close', 'negative_stock', 'buyer_missing',
        'prescription_missing', 'prescription_stale', 'prescription_reused', 'pharmacist_missing', 'pharmacist_unverified',
        'approval_offline', 'lot_company_mismatch',
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Lots $lots,
        private readonly Profiles $profiles,
        private readonly Units $units,
        private readonly Prescriptions $prescriptions,
        private readonly ControlledRegister $register,
        private readonly ScopeResolver $scopes,
        private readonly OverrideVerifier $overrides,
    ) {}

    /** @param array<string, mixed> $sale SaleSnapshot::sale() */
    public function completed(array $sale): void
    {
        $saleId = (string) $sale['id'];
        $flagged = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($sale, $saleId) {
            $this->lock($saleId);
            $state = SaleStockLines::forSale($saleId);

            // Voided before it arrived here: nothing was taken, nothing to bring back.
            if ($state === null || $state['status'] !== 'completed') {
                return [];
            }

            $flagged = $this->take($sale);

            // Refunds applied before this sale was handled come back right after it.
            foreach ($state['refund_ids'] as $refundId) {
                $this->bringBackRefund($refundId);
            }

            return $flagged;
        });

        if ($flagged !== []) {
            DispenseFlagged::dispatch($this->tenants->require(), $saleId, (string) $sale['location_id'], $sale['receipt_number'] ?? null, $flagged);
        }
    }

    public function voided(string $saleId, string $voidId): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($saleId, $voidId) {
            $this->lock($saleId);
            $void = SaleStockLines::forVoid($voidId);

            if ($void === null) {
                return;
            }

            $at = CarbonImmutable::parse($void['occurred_at']);

            foreach (LotMovement::query()->where('sale_id', $saleId)->where('kind', LotMovement::SALE)->orderBy('occurred_at')->orderBy('id')->get() as $movement) {
                $left = Qty::of((string) -$movement->qty)->minus($this->returned($movement));

                if ($left->isPositive()) {
                    $this->giveBack($movement, $left, LotMovement::SALE_VOID, 'pos.void', $voidId, RegisterEntry::RETURN, $at);
                }
            }
        });
    }

    public function refunded(string $saleId, string $refundId): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($saleId, $refundId) {
            $this->lock($saleId);
            $this->bringBackRefund($refundId);
        });
    }

    private function lock(string $saleId): void
    {
        DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['pharmacy.sale:'.$saleId]);
    }

    /** @return list<string> the review flags of the sale's dispenses */
    private function take(array $sale): array
    {
        $company = (string) $sale['company_id'];
        $location = (string) $sale['location_id'];
        $settings = Settings::for($company);
        $lines = $sale['lines'] ?? [];
        $profiles = $this->profiles->many(array_column($lines, 'item_id'));

        if ($profiles->isEmpty()) {
            return [];
        }

        $items = Item::query()->whereKey($profiles->keys()->all())->get()->keyBy('id');
        $at = CarbonImmutable::parse($sale['sold_at']);
        $today = $at->setTimezone(CompanyClock::timezone($company, $sale['branch_id'] ?? null))->toDateString();
        $extension = is_array($sale['extensions']['pharmacy'] ?? null) ? $sale['extensions']['pharmacy'] : [];
        $rxData = is_array($extension['prescription'] ?? null) ? $extension['prescription'] : null;
        $prescription = $this->prescriptions->fromTill($sale, $rxData);
        // PHA-28: a prescription id that is another sale's is never attached; the dispense is flagged instead.
        $reused = $prescription === null && $this->prescriptions->isReused($sale, $rxData);
        $chosen = collect($extension['lines'] ?? [])->filter(fn ($row) => is_array($row))->keyBy(fn (array $row) => (string) ($row['line_id'] ?? ''));
        $approval = null;
        $flaggedAll = [];

        foreach ($lines as $line) {
            $profile = $profiles->get($line['item_id']);
            $item = $items->get($line['item_id']);

            if ($profile === null || $item === null || ! BigDecimal::of((string) $line['qty'])->isPositive()) {
                continue;
            }

            $qty = $this->units->toBase($item, $line['uom_id'] ?? null, (string) $line['qty']);
            $needsRx = $profile->needsPrescription($settings);
            $needsPharmacist = $profile->needsPharmacist($settings);
            $controlled = $profile->isControlled();
            $flags = [];
            $taken = [];

            if ($profile->batch_tracked) {
                [$taken, $lotFlags] = $this->allocate($profile, $company, $location, $settings, $today, $qty, $chosen->get((string) $line['id'])['lots'] ?? null);
                $flags = [...$flags, ...$lotFlags];
            }

            if ($needsRx) {
                if ($prescription === null || $prescription->status !== Prescription::VALID) {
                    $flags[] = 'prescription_missing';

                    if ($reused) {
                        $flags[] = 'prescription_reused';
                    }
                } elseif (Prescriptions::isStale($prescription, $settings->prescription_valid_days, $today)) {
                    $flags[] = 'prescription_stale';
                }
            }

            $mode = Dispense::NONE;
            $pharmacist = null;

            if ($needsPharmacist) {
                $approval ??= $this->approval($sale, $extension['approval'] ?? null);
                [$mode, $pharmacist] = [$approval['mode'], $approval['pharmacist']];
                $flags = [...$flags, ...$approval['flags']];
            }

            if ($profile->restricted_sale && empty($sale['customer_id'])) {
                $flags[] = 'buyer_missing';
            }

            $attached = $needsRx && $prescription?->status === Prescription::VALID ? $prescription : null;

            foreach ($taken as $take) {
                $moved = $this->lots->move(
                    $take['lot'], $location, LotMovement::SALE, $take['qty']->negated(), 'pos.sale', (string) $sale['id'], (string) $line['id'], $at,
                    $take['flags'], (string) $sale['id'], $sale['customer_id'] ?? null, $attached?->id, null, null, $sale['receipt_number'] ?? null,
                );
                // What the ledger found while moving (stock below zero).
                $flags = [...$flags, ...($moved?->flags ?? [])];

                if ($controlled) {
                    $this->register->append($company, $location, $item->id, RegisterEntry::DISPENSE, $take['qty']->negated(), 'pos.sale', (string) $sale['id'], (string) $line['id'], $take['lot'], $attached ?? $prescription, $pharmacist, null, null, $at);
                }
            }

            if ($controlled && $taken === []) {
                $this->register->append($company, $location, $item->id, RegisterEntry::DISPENSE, $qty->negated(), 'pos.sale', (string) $sale['id'], (string) $line['id'], null, $attached ?? $prescription, $pharmacist, null, null, $at);
            }

            $flags = array_values(array_unique($flags));
            $review = array_values(array_intersect($flags, self::REVIEW_FLAGS));

            if ($needsRx || $needsPharmacist || $controlled || $review !== []) {
                $made = Dispense::query()->firstOrCreate(['sale_line_id' => $line['id']], [
                    'company_id' => $company,
                    'branch_id' => $sale['branch_id'],
                    'location_id' => $location,
                    'sale_id' => $sale['id'],
                    'receipt_number' => $sale['receipt_number'] ?? null,
                    'item_id' => $item->id,
                    'class' => $profile->class,
                    'qty' => (string) $qty,
                    'customer_id' => $sale['customer_id'] ?? null,
                    'prescription_id' => $attached?->id,
                    'pharmacist_id' => $pharmacist,
                    'cashier_id' => $sale['cashier_id'] ?? null,
                    'approval_mode' => $mode,
                    'flags' => $flags,
                    'review_status' => $review === [] ? Dispense::REVIEW_NONE : Dispense::REVIEW_OPEN,
                    'dispensed_at' => $at,
                ]);

                if ($made->wasRecentlyCreated) {
                    $flaggedAll = [...$flaggedAll, ...$review];
                }
            }
        }

        return array_values(array_unique($flaggedAll));
    }

    /**
     * The lots a line takes: the till's choice (valid lots of the item, in
     * the order sent), then first-expiry-first-out for what is left. Each
     * with the flags the server finds.
     *
     * @param  list<array<string, mixed>>|null  $choice
     * @return array{0: list<array{lot: Lot, qty: BigDecimal, flags: list<string>}>, 1: list<string>}
     */
    private function allocate(ItemProfile $profile, string $company, string $location, Settings $settings, string $today, BigDecimal $qty, ?array $choice): array
    {
        $taken = [];
        $line = [];
        $left = $qty;
        $earliest = $this->lots->suggest($profile->item_id, $location, $settings, $today);

        foreach ((array) $choice as $pick) {
            $lot = is_array($pick) && Str::isUuid((string) ($pick['lot_id'] ?? '')) ? Lot::query()->find($pick['lot_id']) : null;
            $want = is_array($pick) && is_numeric($pick['qty'] ?? null) ? Qty::of((string) $pick['qty']) : Qty::zero();

            if ($lot === null || $lot->item_id !== $profile->item_id || ! $want->isPositive() || ! $left->isPositive()) {
                continue;
            }

            // A lot of another company is never taken from (the rest falls back to first-expiry-first-out).
            if ($lot->company_id !== $company) {
                $line[] = 'lot_company_mismatch';

                continue;
            }

            $want = Qty::min($want, $left);
            $verdict = $this->lots->check($lot, $settings, $today);
            $flags = [];

            if ($verdict['refuse'] !== null) {
                $flags[] = $verdict['refuse'];
            } elseif ($verdict['warn'] !== null) {
                $flags[] = $verdict['warn'];
            }

            // Another sellable lot expired sooner and had stock: not first-expiry-first-out (recorded, not reviewed).
            $sooner = collect($earliest['lots'])->first(fn (array $row) => $row['lot']->id !== $lot->id && $row['lot']->expires_on->lessThan($lot->expires_on));

            if ($sooner !== null && $verdict['refuse'] === null) {
                $flags[] = 'not_fefo';
            }

            $taken[] = ['lot' => $lot, 'qty' => $want, 'flags' => $flags];
            $left = $left->minus($want);
        }

        if ($choice === null || $choice === []) {
            $line[] = 'batch_not_chosen';
        }

        if ($left->isPositive()) {
            $line[] = 'batch_not_chosen';
            $fefo = $this->lots->suggest($profile->item_id, $location, $settings, $today, $left);

            foreach ($fefo['lots'] as $row) {
                if (BigDecimal::of($row['take'])->isPositive()) {
                    $taken[] = ['lot' => $row['lot'], 'qty' => Qty::of($row['take']), 'flags' => ['batch_not_chosen', ...($row['warn'] === null ? [] : [$row['warn']])]];
                }
            }

            $left = Qty::of($fefo['shortfall']);
        }

        // Nothing sellable covers the rest: it goes against the lot expiring soonest that has any stock here, or the line's first lot, flagged, so stock never hides.
        if ($left->isPositive()) {
            $line[] = 'negative_stock';
            $fallback = $taken[0]['lot'] ?? $this->fallbackLot($profile->item_id, $location);

            if ($fallback !== null) {
                $verdict = $this->lots->check($fallback, $settings, $today);
                $taken[] = ['lot' => $fallback, 'qty' => $left, 'flags' => $verdict['refuse'] === null ? [] : [$verdict['refuse']]];
            }
        }

        $lineFlags = array_merge($line, ...array_map(fn (array $take) => $take['flags'], $taken));

        return [$this->merge($taken), array_values(array_unique($lineFlags))];
    }

    /** The lot with stock at the place, or the one nearest to expiry that ever had; null when the item has no lot at the company. */
    private function fallbackLot(string $itemId, string $location): ?Lot
    {
        $balance = DB::connection(TenantContext::CONNECTION)->table('pharmacy_lot_balances')->where('item_id', $itemId)->where('location_id', $location)->orderByDesc('qty')->value('lot_id');

        return $balance === null ? null : Lot::query()->find($balance);
    }

    /**
     * One entry per lot (the same lot picked twice is added up).
     *
     * @param  list<array{lot: Lot, qty: BigDecimal, flags: list<string>}>  $taken
     * @return list<array{lot: Lot, qty: BigDecimal, flags: list<string>}>
     */
    private function merge(array $taken): array
    {
        $by = [];

        foreach ($taken as $take) {
            $id = $take['lot']->id;

            if (isset($by[$id])) {
                $by[$id]['qty'] = $by[$id]['qty']->plus($take['qty']);
                $by[$id]['flags'] = array_values(array_unique([...$by[$id]['flags'], ...$take['flags']]));
            } else {
                $by[$id] = $take;
            }
        }

        return array_values($by);
    }

    /**
     * PHA-17: who approved a restricted dispense. The cashier's own right at
     * the location, else the pharmacist's signed override redeemed once for
     * the sale; otherwise nobody (flagged).
     *
     * @param  array<string, mixed>  $sale
     * @param  array<string, mixed>|null  $override
     * @return array{mode: string, pharmacist: ?string, flags: list<string>}
     */
    private function approval(array $sale, ?array $override): array
    {
        $cashier = Str::isUuid((string) ($sale['cashier_id'] ?? '')) ? User::query()->find($sale['cashier_id']) : null;
        $place = Scope::location((string) $sale['location_id']);

        $entitled = $cashier !== null && $this->scopes->can($cashier, 'pharmacy.dispense.authorise', $place);
        // AUTH-07: the cashier's right counts only when the till proved who was signed in (POS flags `actor_unverified` otherwise).
        $unproven = in_array('actor_unverified', array_map(fn ($flag) => is_array($flag) ? ($flag['code'] ?? null) : $flag, (array) ($sale['flags'] ?? [])), true);

        if ($entitled && ! $unproven) {
            return ['mode' => Dispense::SELF, 'pharmacist' => $cashier->id, 'flags' => []];
        }

        if (! is_array($override) || $override === []) {
            return $entitled
                ? ['mode' => Dispense::NONE, 'pharmacist' => null, 'flags' => ['pharmacist_unverified']]
                : ['mode' => Dispense::NONE, 'pharmacist' => null, 'flags' => ['pharmacist_missing']];
        }

        $device = Str::isUuid((string) ($sale['device_id'] ?? '')) ? Device::query()->find($sale['device_id']) : null;

        try {
            $proof = $device === null ? null : $this->overrides->redeem($device, $override, 'pharmacy.dispense.authorise', (string) $sale['id']);
        } catch (Rejection|Throwable) {
            $proof = null;
        }

        if ($proof === null || ! $proof->qualifies) {
            return ['mode' => Dispense::OVERRIDE, 'pharmacist' => $proof?->managerUserId, 'flags' => ['pharmacist_unverified']];
        }

        return ['mode' => Dispense::OVERRIDE, 'pharmacist' => $proof->managerUserId, 'flags' => $proof->offline ? ['approval_offline'] : []];
    }

    /** What a sale movement's line already got back (voids and refunds), as a positive quantity. */
    private function returned(LotMovement $sale): BigDecimal
    {
        $back = LotMovement::query()->where('lot_id', $sale->lot_id)->where('location_id', $sale->location_id)->where('sale_id', $sale->sale_id)
            ->whereIn('kind', [LotMovement::SALE_RETURN, LotMovement::SALE_VOID])
            ->when($sale->source_line_id === null, fn ($q) => $q->whereNull('source_line_id'), fn ($q) => $q->where('source_line_id', $sale->source_line_id))
            ->sum('qty');

        return Qty::of((string) $back);
    }

    /** Bring $qty (positive) of a sale movement's lot back. */
    private function giveBack(LotMovement $sale, BigDecimal $qty, string $kind, string $sourceType, string $sourceId, string $registerKind, CarbonImmutable $at): void
    {
        $lot = Lot::query()->find($sale->lot_id);

        if ($lot === null) {
            return;
        }

        $made = $this->lots->move($lot, $sale->location_id, $kind, $qty, $sourceType, $sourceId, $sale->source_line_id, $at, [], $sale->sale_id, $sale->customer_id, $sale->prescription_id, null, null, $sale->reference);

        if ($made !== null && $this->profiles->of($sale->item_id)?->isControlled()) {
            $prescription = $sale->prescription_id === null ? null : Prescription::query()->find($sale->prescription_id);
            $this->register->append($sale->company_id, $sale->location_id, $sale->item_id, $registerKind, $qty, $sourceType, $sourceId, $sale->source_line_id, $lot, $prescription, null, null, null, $at);
        }
    }

    /** A refund's lines come back into the lots their sale line was taken from, first-sold first. */
    private function bringBackRefund(string $refundId): void
    {
        $refund = SaleStockLines::forRefund($refundId);

        if ($refund === null) {
            return;
        }

        $at = CarbonImmutable::parse($refund['occurred_at']);
        $items = Item::query()->whereKey(array_column($refund['lines'], 'item_id'))->get()->keyBy('id');

        foreach ($refund['lines'] as $line) {
            $item = $items->get($line['item_id']);

            if ($item === null) {
                continue;
            }

            $left = $this->units->toBase($item, $line['uom_id'] ?? null, (string) $line['qty']);

            foreach (LotMovement::query()->where('sale_id', $refund['sale_id'])->where('source_line_id', $line['sale_line_id'])->where('kind', LotMovement::SALE)->orderBy('occurred_at')->orderBy('id')->get() as $movement) {
                if (! $left->isPositive()) {
                    break;
                }

                $room = Qty::of((string) -$movement->qty)->minus($this->returned($movement));
                $give = Qty::min($left, $room);

                if ($give->isPositive()) {
                    $this->giveBack($movement, $give, LotMovement::SALE_RETURN, 'pos.refund', $refundId, RegisterEntry::RETURN, $at);
                    $left = $left->minus($give);
                }
            }
        }
    }
}
