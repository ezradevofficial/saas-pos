<?php

namespace Modules\Procurement\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Modules\Procurement\Models\Bid;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\BidFile;
use Modules\Procurement\Models\BidScore;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Models\TenderCriterion;
use Modules\Procurement\Models\TenderLine;
use Modules\Procurement\Models\TenderMember;
use Modules\Procurement\Models\TenderOpening;
use Modules\Procurement\Support\Places;
use Modules\Procurement\Tenders\Tenders;

/**
 * PRC-06 to PRC-11: a tender; in the detail its lines, criteria, committee,
 * openings, bidders and bids. PRC-09 and decision 22: before the opening
 * staff see only the number of bids and the invited suppliers' names;
 * bids (who, when, version, fingerprint, then their content), open-notice
 * registrants and bidders' contact details appear once the tender is
 * opened.
 *
 * @mixin Tender
 */
class TenderResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Tender $tender */
        $tender = $this->resource;
        $user = $request->user();
        $places = app(Places::class);
        $at = fn (string $permission) => $places->at($user, "procurement.tender.{$permission}", $tender->branch_id);

        return [
            'id' => $tender->id,
            'number' => $tender->number,
            'status' => $tender->shownStatus(),
            'kind' => $tender->kind,
            'title' => $tender->title,
            'description' => $tender->description,
            'currency' => $tender->currency,
            'deadline_at' => Refs::at($tender->deadline_at),
            'opening_at' => Refs::at($tender->opening_at),
            'technical_weight' => $tender->technical_weight,
            'financial_weight' => $tender->financial_weight,
            'pass_mark' => Refs::qty($tender->pass_mark),
            'company' => Refs::named($tender->company),
            'branch' => Refs::named($tender->branch),
            'created_by' => Refs::user($tender->creator),
            'bids_count' => $tender->bids_count ?? $tender->bids()->whereIn('status', [Bid::SUBMITTED, Bid::OPENED, Bid::TAMPERED])->count(),
            'published_at' => Refs::at($tender->published_at),
            'opened_at' => Refs::at($tender->opened_at),
            'evaluated_at' => Refs::at($tender->evaluated_at),
            'awarded_at' => Refs::at($tender->awarded_at),
            'award_reason' => $tender->award_reason,
            'cancelled_at' => Refs::at($tender->cancelled_at),
            'cancel_reason' => $tender->cancel_reason,
            'created_at' => Refs::at($tender->created_at),
            ...($this->detail ? $this->detailOf($tender, $request, $at) : []),
        ];
    }

    /** @return array<string, mixed> */
    private function detailOf(Tender $tender, Request $request, \Closure $at): array
    {
        $user = $request->user();
        $opened = $tender->opened_at !== null;
        $members = $tender->members()->with('user')->get();
        $openings = $tender->openings()->with('user')->orderBy('confirmed_at')->get();
        $scores = $opened ? BidScore::query()->whereIn('bid_id', $tender->bids()->select('id'))->get()->groupBy('bid_id') : collect();
        $isOpener = $members->contains(fn (TenderMember $m) => $m->user_id === $user->id && $m->role === TenderMember::OPENER);
        $isEvaluator = $members->contains(fn (TenderMember $m) => $m->user_id === $user->id && $m->role === TenderMember::EVALUATOR);

        return [
            // Stage 7B walkthrough: how long a bidder's link works after the deadline (the invite dialog says so).
            'link_days_after_deadline' => (int) config('procurement.portal.link_days_after_deadline', 90),
            'notice_url' => $tender->kind === Tender::OPEN && Tenders::noticeToken($tender) !== null
                ? rtrim((string) config('app.frontend_url'), '/').'/supplier-portal/notices/'.Tenders::noticeToken($tender) : null,
            'lines' => $tender->lines()->with(['item', 'uom'])->get()->map(fn (TenderLine $line) => [
                'id' => $line->id, 'line_no' => $line->line_no, 'item' => Refs::item($line->item), 'description' => $line->description,
                'uom' => Refs::uom($line->uom), 'qty' => Refs::qty($line->qty),
            ])->all(),
            'criteria' => $tender->criteria()->get()->map(fn (TenderCriterion $c) => ['id' => $c->id, 'name' => $c->name, 'weight' => $c->weight, 'max_score' => $c->max_score])->all(),
            'openers' => $members->where('role', TenderMember::OPENER)->map(fn (TenderMember $m) => Refs::user($m->user))->values()->all(),
            'evaluators' => $members->where('role', TenderMember::EVALUATOR)->map(fn (TenderMember $m) => Refs::user($m->user))->values()->all(),
            'openings' => $openings->map(fn (TenderOpening $o) => ['user' => Refs::user($o->user), 'confirmed_at' => Refs::at($o->confirmed_at)])->all(),
            // Decision 22 (owner, 2026-10-14): before the opening, staff see only the invited suppliers' names
            // (no email, tax ID or last visit) and the number of bids; registrants and bids appear once opened.
            'bidders' => $tender->bidders()->with('party')
                ->when(! $opened, fn ($q) => $q->where('source', Bidder::INVITED))
                ->orderBy('created_at')->orderBy('id')->get()->map(fn (Bidder $bidder) => [
                    'id' => $bidder->id, 'name' => $bidder->name, 'source' => $bidder->source, 'party' => Refs::named($bidder->party),
                    ...($opened ? ['email' => $bidder->email, 'tax_id' => $bidder->tax_id, 'last_seen_at' => Refs::at($bidder->last_seen_at)] : []),
                    'link_sent_at' => Refs::at($bidder->link_sent_at),
                    'removed_at' => Refs::at($bidder->removed_at),
                    'can' => ['resend' => $tender->status === Tender::PUBLISHED && ! $tender->closedForBids() && $bidder->removed_at === null,
                        'remove' => in_array($tender->status, [Tender::DRAFT, Tender::PUBLISHED], true) && ! $tender->closedForBids() && $bidder->removed_at === null],
                ])->all(),
            // Review: bids in the order they were submitted, then by id (a stable order).
            'bids' => ! $opened ? [] : $tender->bids()->with(['bidder', 'files'])->orderBy('submitted_at')->orderBy('id')->get()
                ->map(fn (Bid $bid) => self::bid($bid, $scores->get($bid->id, collect()), (string) $tender->company->base_currency))->all(),
            // PRC-11: the draft PO the award made.
            'order' => $tender->awarded_bid_id === null ? null : (($order = Order::query()->where('bid_id', $tender->awarded_bid_id)->first(['id', 'number', 'status'])) === null ? null
                : ['id' => $order->id, 'number' => $order->number, 'status' => $order->status]),
            'can' => [
                // PRC-12: award and regret letters, once awarded.
                'letter' => $tender->status === Tender::AWARDED,
                'email_letter' => $tender->status === Tender::AWARDED && $at('award'),
                'edit' => $tender->status === Tender::DRAFT && $at('create'),
                'publish' => $tender->status === Tender::DRAFT && $at('create'),
                'invite' => in_array($tender->status, [Tender::DRAFT, Tender::PUBLISHED], true) && ! ($tender->status === Tender::PUBLISHED && $tender->closedForBids()) && $at('create'),
                'open' => $tender->status === Tender::PUBLISHED && CarbonImmutable::now()->greaterThanOrEqualTo($tender->opening_at) && $isOpener && $at('open')
                    && ! $openings->contains('user_id', $user->id),
                'score' => in_array($tender->status, [Tender::OPENED, Tender::EVALUATED], true) && $isEvaluator && $at('evaluate'),
                'award' => in_array($tender->status, [Tender::OPENED, Tender::EVALUATED], true) && $at('award'),
                'cancel' => ! in_array($tender->status, [Tender::AWARDED, Tender::CANCELLED], true) && $at('cancel'),
            ],
        ];
    }

    /**
     * PRC-09: a bid as buyers see it: sealed (who, when, version,
     * fingerprint) until opened, its content and scores after.
     *
     * @param  Collection<int, BidScore>  $scores
     * @return array<string, mixed>
     */
    public static function bid(Bid $bid, $scores, string $baseCurrency): array
    {
        $sealed = [
            'id' => $bid->id,
            'bidder' => ['id' => $bid->bidder->id, 'name' => $bid->bidder->name],
            'status' => $bid->status,
            'version' => $bid->version,
            'submitted_at' => Refs::at($bid->submitted_at),
            'withdrawn_at' => Refs::at($bid->withdrawn_at),
            'fingerprint' => $bid->fingerprint,
            'sealed' => ! $bid->isOpened(),
        ];

        if (! $bid->isOpened()) {
            return $sealed;
        }

        return [
            ...$sealed,
            'opened_at' => Refs::at($bid->opened_at),
            'currency' => $bid->currency,
            'fx' => Refs::fx($bid->fx),
            'total' => Refs::money($bid->total_minor, $bid->currency),
            // CUR-04: the base total is in the company's base currency, never the snapshot's base() (the bid currency for a USD/KES rate).
            'base_total' => Refs::money($bid->base_total_minor, $baseCurrency),
            'complete' => $bid->complete,
            'lines' => array_map(fn (array $line) => [
                'tender_line_id' => $line['tender_line_id'],
                'unit_price' => Refs::money($line['unit_price_minor'], $bid->currency),
                'amount' => Refs::money($line['amount_minor'], $bid->currency),
            ], $bid->lines ?? []),
            'technical_response' => $bid->technical_response,
            'files' => $bid->files->map(fn (BidFile $file) => ['id' => $file->id, 'name' => $file->name, 'mime' => $file->mime, 'size' => $file->size])->values()->all(),
            'technical_score_bp' => $bid->technical_score_bp,
            'financial_score_bp' => $bid->financial_score_bp,
            'combined_score_bp' => $bid->combined_score_bp,
            'rank' => $bid->rank,
            'outcome' => $bid->outcome,
            'scores' => $scores->map(fn (BidScore $s) => ['criterion_id' => $s->criterion_id, 'evaluator_id' => $s->evaluator_id, 'score' => Refs::qty($s->score), 'comment' => $s->comment])->values()->all(),
        ];
    }
}
