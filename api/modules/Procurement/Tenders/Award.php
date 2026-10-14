<?php

namespace Modules\Procurement\Tenders;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Sharing\MasterDataSharing;
use Carbon\CarbonImmutable;
use Modules\Procurement\Documents\Orders;
use Modules\Procurement\Events\TenderAwarded;
use Modules\Procurement\Models\Bid;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Models\TenderLine;
use Modules\Procurement\Support\ProcurementError;
use Modules\Procurement\Support\Tx;

/**
 * PRC-11: the award. One passing bid wins (the top-ranked by default;
 * another needs a reason, audited on the tender): an outside bidder becomes
 * a core supplier party (shared or of the tender's company, as suppliers
 * are kept, TEN-08), a draft PO is created from the bid's lines in its
 * currency, the other opened bids are regretted (disqualified ones stay
 * so), and the tender is `awarded`. Then TenderAwarded.
 */
class Award
{
    public function __construct(
        private readonly Evaluation $evaluation,
        private readonly Orders $orders,
    ) {}

    public function award(Tender $tender, ?string $bidId, ?string $reason, User $by): Tender
    {
        $event = null;

        $awarded = Tx::run(function () use ($tender, $bidId, $reason, $by, &$event) {
            $tender = Tender::query()->whereKey($tender->id)->lockForUpdate()->firstOrFail();

            if (! in_array($tender->status, [Tender::OPENED, Tender::EVALUATED], true)) {
                throw ProcurementError::make('tender_not_opened', status: 409);
            }

            $this->evaluation->rank($tender);

            if ($tender->status !== Tender::EVALUATED) {
                throw ProcurementError::make('not_evaluated', status: 409);
            }

            $bids = Bid::query()->where('tender_id', $tender->id)->where('status', Bid::OPENED)->lockForUpdate()->get();
            $top = $bids->firstWhere('rank', 1);
            $bid = $bidId === null ? $top : $bids->firstWhere('id', $bidId);

            if ($bid === null || $bid->rank === null) {
                throw ProcurementError::make('bid_not_passing', field: 'bid_id');
            }

            if ($bid->id !== $top?->id && trim((string) $reason) === '') {
                throw ProcurementError::make('award_reason_required', field: 'reason');
            }

            $bidder = Bidder::query()->lockForUpdate()->findOrFail($bid->bidder_id);
            $party = $bidder->party_id === null ? $this->supplierFor($tender, $bidder) : Party::query()->findOrFail($bidder->party_id);
            $tenderLines = TenderLine::query()->where('tender_id', $tender->id)->get()->keyBy('id');
            $order = $this->orders->create([
                'branch_id' => $tender->branch_id,
                'party_id' => $party->id,
                'currency' => $bid->currency,
                'terms' => $tender->title,
                'lines' => array_map(function (array $line) use ($tenderLines) {
                    $tenderLine = $tenderLines->get($line['tender_line_id']);

                    return ['item_id' => $tenderLine->item_id, 'description' => $tenderLine->description, 'uom_id' => $tenderLine->uom_id,
                        'qty' => (string) $tenderLine->qty, 'unit_price_minor' => $line['unit_price_minor']];
                }, $bid->lines ?? []),
            ], $by, ['bid_id' => $bid->id, 'requisition_id' => $tender->requisition_id]);

            foreach ($bids as $other) {
                $other->fill(['outcome' => $other->id === $bid->id ? Bid::AWARDED : ($other->outcome === Bid::DISQUALIFIED ? Bid::DISQUALIFIED : Bid::REGRETTED)])->save();
            }

            $tender->fill([
                'status' => Tender::AWARDED,
                'awarded_at' => CarbonImmutable::now(),
                'awarded_by' => $by->id,
                'awarded_bid_id' => $bid->id,
                'award_reason' => $bid->id === $top?->id ? null : trim((string) $reason),
            ])->save();
            $event = new TenderAwarded((string) $tender->tenant_id, $tender->id, $tender->company_id, $bid->id, $party->id, $order->id);

            return $tender->refresh();
        });

        event($event);

        return $awarded;
    }

    /** MD-01, TEN-08: an outside bidder becomes a supplier party (it keeps the link). */
    private function supplierFor(Tender $tender, Bidder $bidder): Party
    {
        $party = Party::query()->create([
            'company_id' => app(MasterDataSharing::class)->isShared('suppliers') ? null : $tender->company_id,
            'kind' => 'organisation',
            'name' => $bidder->name,
            'tax_id' => $bidder->tax_id,
            'emails' => [['address' => strtolower($bidder->email), 'label' => null]],
            'roles' => ['supplier'],
        ]);
        $bidder->fill(['party_id' => $party->id])->save();

        return $party;
    }

    /** Whether a draft PO was made from this bid (for the detail page). */
    public static function orderOf(Bid $bid): ?Order
    {
        return Order::query()->where('bid_id', $bid->id)->first();
    }
}
