<?php

namespace Modules\Pharmacy\Recalls;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Pharmacy\Events\LotBlocked;
use Modules\Pharmacy\Events\RecallIssued;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\LotMovement;
use Modules\Pharmacy\Models\Recall;
use Modules\Pharmacy\Stock\Lots;
use Modules\Pharmacy\Support\PharmacyError;
use Modules\Pharmacy\Support\Qty;

/**
 * PHA-22, PHA-23: recalls of one batch of an item, tenant-wide.
 *
 * - issue(): one open recall per item and batch number. Every lot of that
 *   item and batch number (whatever the company) is blocked at once: its
 *   `recall_id` is set, so it cannot be sold, transferred or released at
 *   any location, and tills learn it on their next pull. A lot received
 *   later with a recalled batch number is blocked from the start
 *   (Lots::ensure). The lot's own quarantine is left alone.
 * - lift(): the lots are unblocked (a lot that was also quarantined for
 *   another reason stays quarantined).
 * - customers(): who bought the recalled lots, from the sale ledger,
 *   refunded and voided quantities netted.
 */
class Recalls
{
    public function __construct(private readonly Lots $lots, private readonly Auditor $auditor) {}

    public function issue(string $itemId, string $batchNumber, string $reason, ?string $reference, User $by): Recall
    {
        $batchNumber = trim($batchNumber);

        try {
            return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($itemId, $batchNumber, $reason, $reference, $by) {
                $recall = Recall::create([
                    'item_id' => $itemId,
                    'batch_number' => $batchNumber,
                    'reference' => $reference,
                    'reason' => $reason,
                    'status' => Recall::OPEN,
                    'issued_by' => $by->id,
                    'issued_at' => CarbonImmutable::now(),
                ]);
                $lots = Lot::query()->where('item_id', $itemId)->whereRaw('lower(batch_number) = lower(?)', [$batchNumber])->lockForUpdate()->get();
                Lot::query()->whereKey($lots->pluck('id')->all())->update(['recall_id' => $recall->id]);
                $this->lots->touch(...$lots->pluck('id')->all());
                $this->auditor->record('pharmacy.recall.issue', $recall, null, ['item_id' => $itemId, 'batch_number' => $batchNumber, 'reference' => $reference, 'lots' => $lots->count()]);

                foreach ($lots as $lot) {
                    LotBlocked::dispatch($recall->tenant_id, $lot->id, $lot->company_id, $itemId, 'recalled');
                }

                RecallIssued::dispatch($recall->tenant_id, $recall->id, $itemId, $batchNumber, $lots->pluck('id')->all());

                return $recall->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw PharmacyError::make(409, 'recall_open', ['batch' => $batchNumber], 'batch_number');
        }
    }

    public function lift(Recall $recall, string $reason, User $by): Recall
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($recall, $reason, $by) {
            $recall = Recall::query()->whereKey($recall->id)->lockForUpdate()->firstOrFail();

            if ($recall->status !== Recall::OPEN) {
                throw PharmacyError::make(409, 'recall_lifted');
            }

            $recall->fill(['status' => Recall::LIFTED, 'lifted_by' => $by->id, 'lifted_at' => CarbonImmutable::now(), 'lift_reason' => $reason])->save();
            $ids = Lot::query()->where('recall_id', $recall->id)->pluck('id')->all();
            Lot::query()->whereKey($ids)->update(['recall_id' => null]);
            $this->lots->touch(...$ids);
            $this->auditor->record('pharmacy.recall.lift', $recall, ['status' => Recall::OPEN], ['status' => Recall::LIFTED, 'reason' => $reason, 'lots' => count($ids)]);

            return $recall->refresh();
        });
    }

    /**
     * PHA-23: the sales of the recalled item and batch number, by sale, with what is
     * still with the customer (sold less voided and refunded); sales whose
     * customer was not named are counted apart.
     *
     * @return array{customers: list<array<string, mixed>>, unattributed: array{sales: int, qty: string}}
     */
    public function customers(Recall $recall): array
    {
        $lotIds = Lot::query()->where('item_id', $recall->item_id)->whereRaw('lower(batch_number) = lower(?)', [$recall->batch_number])->pluck('id')->all();
        $rows = LotMovement::query()->whereIn('lot_id', $lotIds)->whereNotNull('sale_id')
            ->whereIn('kind', [LotMovement::SALE, LotMovement::SALE_VOID, LotMovement::SALE_RETURN])
            ->get(['sale_id', 'customer_id', 'location_id', 'reference', 'kind', 'qty', 'occurred_at']);
        $parties = Party::query()->whereKey($rows->pluck('customer_id')->filter()->unique()->all())->get()->keyBy('id');
        $customers = [];
        $unattributed = ['sales' => 0, 'qty' => Qty::zero()];

        foreach ($rows->groupBy('sale_id') as $saleId => $movements) {
            $sold = $movements->firstWhere('kind', LotMovement::SALE);
            // Sold is negative on the ledger, voids and refunds positive: what is left with the customer.
            $kept = $movements->reduce(fn ($sum, LotMovement $m) => $sum->plus(Qty::of((string) $m->qty)), Qty::zero())->negated();

            if ($sold === null || ! $kept->isPositive()) {
                continue;
            }

            $party = $sold->customer_id === null ? null : $parties->get($sold->customer_id);

            if ($party === null) {
                $unattributed['sales']++;
                $unattributed['qty'] = $unattributed['qty']->plus($kept);

                continue;
            }

            $customers[] = [
                'customer' => ['id' => $party->id, 'name' => $party->name, 'phone' => $party->phoneNumbers()[0] ?? null],
                'sale_id' => $saleId,
                'receipt_number' => $sold->reference,
                'location_id' => $sold->location_id,
                'qty' => Qty::show($kept),
                'sold_at' => $sold->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ];
        }

        usort($customers, fn (array $a, array $b) => [$a['customer']['name'], $a['sold_at']] <=> [$b['customer']['name'], $b['sold_at']]);

        return ['customers' => $customers, 'unattributed' => ['sales' => $unattributed['sales'], 'qty' => Qty::show($unattributed['qty'])]];
    }
}
