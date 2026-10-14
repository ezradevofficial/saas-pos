<?php

namespace Modules\Procurement\Tenders;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Modules\Procurement\Models\Bid;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Portal\PortalAuth;
use Modules\Procurement\Portal\PortalNotices;
use Modules\Procurement\Support\LineResolver;
use Modules\Procurement\Support\ProcurementError;
use Modules\Procurement\Support\Tx;

/**
 * PRC-07, PRC-08: who may bid on a tender.
 *
 * - add(): the buyer invites a supplier party (its first email, or one
 *   typed) or an outsider by name, tax ID and email; one bidder per email
 *   per tender (`already_invited`). Bidders of a published tender get their
 *   link at once; those added to a draft get it when it is published.
 * - register(): an outsider registers on an open tender's public notice
 *   (PRC-08); the link goes to the email typed, which proves it.
 * - resend(): a new link (the old one stops working); remove(): the bidder
 *   is removed, its link and sessions end, a bid it sent is withdrawn.
 * - sendLinks(): links to every bidder without one (publication).
 */
class Bidders
{
    public function __construct(
        private readonly LineResolver $resolver,
        private readonly PortalAuth $auth,
        private readonly PortalNotices $notices,
        private readonly Auditor $auditor,
    ) {}

    /** @param array{party_id?: ?string, name?: ?string, email?: ?string, tax_id?: ?string} $data */
    public function add(Tender $tender, array $data, User $by): Bidder
    {
        return Tx::run(function () use ($tender, $data, $by) {
            $tender = Tender::query()->whereKey($tender->id)->lockForUpdate()->firstOrFail();
            $this->assertOpenForBidders($tender);
            $party = empty($data['party_id']) ? null : $this->resolver->supplier($tender->company_id, $data['party_id']);
            $email = strtolower(trim((string) ($data['email'] ?? $party?->emails[0]['address'] ?? '')));

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw ProcurementError::make('bidder_email_required', field: 'email');
            }

            $name = trim((string) ($data['name'] ?? $party?->name ?? ''));

            if ($name === '') {
                throw ProcurementError::make('bidder_name_required', field: 'name');
            }

            $bidder = $this->create($tender, [
                'party_id' => $party?->id,
                'name' => $name,
                'tax_id' => $data['tax_id'] ?? $party?->tax_id,
                'email' => $email,
                'source' => Bidder::INVITED,
                'invited_by' => $by->id,
            ]);

            if ($tender->status === Tender::PUBLISHED) {
                $this->send($bidder, $tender);
            }

            return $bidder->refresh();
        });
    }

    /** @param array{name: string, email: string, tax_id?: ?string} $data */
    public function register(Tender $tender, array $data, ?string $ip): void
    {
        Tx::run(function () use ($tender, $data, $ip) {
            $tender = Tender::query()->whereKey($tender->id)->lockForUpdate()->firstOrFail();

            if ($tender->kind !== Tender::OPEN || $tender->status !== Tender::PUBLISHED || $tender->closedForBids()) {
                throw ProcurementError::make('tender_not_open_for_bids', status: 409);
            }

            $bidder = $this->create($tender, [
                'name' => trim($data['name']),
                'tax_id' => $data['tax_id'] ?? null,
                'email' => strtolower(trim($data['email'])),
                'source' => Bidder::REGISTERED,
            ]);
            $this->auditor->record('procurement.portal.registered', $bidder, null, ['tender' => $tender->id, 'email' => $bidder->email, 'ip' => $ip], ['ip' => $ip]);
            $this->send($bidder, $tender);
        });
    }

    public function resend(Bidder $bidder): Bidder
    {
        return Tx::run(function () use ($bidder) {
            $bidder = Bidder::query()->whereKey($bidder->id)->lockForUpdate()->firstOrFail();
            $tender = Tender::query()->findOrFail($bidder->tender_id);

            if ($bidder->removed_at !== null || $tender->status !== Tender::PUBLISHED || $tender->closedForBids()) {
                throw ProcurementError::make('tender_not_open_for_bids', status: 409);
            }

            $this->send($bidder, $tender);

            return $bidder->refresh();
        });
    }

    public function remove(Bidder $bidder): Bidder
    {
        return Tx::run(function () use ($bidder) {
            $bidder = Bidder::query()->whereKey($bidder->id)->lockForUpdate()->firstOrFail();
            $tender = Tender::query()->findOrFail($bidder->tender_id);

            if ($bidder->removed_at !== null || ! in_array($tender->status, [Tender::DRAFT, Tender::PUBLISHED], true) || $tender->closedForBids()) {
                throw ProcurementError::make('document_not_open', status: 409);
            }

            $bidder->forceFill(['removed_at' => CarbonImmutable::now(), 'token_hash' => null])->save();
            $this->auth->revokeBidder($bidder);
            Bid::query()->where('bidder_id', $bidder->id)->where('status', Bid::SUBMITTED)->get()
                ->each(fn (Bid $bid) => $bid->fill(['status' => Bid::WITHDRAWN, 'withdrawn_at' => CarbonImmutable::now()])->save());

            return $bidder->refresh();
        });
    }

    /** Publication: a link to every bidder that has none. */
    public function sendLinks(Tender $tender): void
    {
        foreach (Bidder::query()->where('tender_id', $tender->id)->whereNull('removed_at')->whereNull('token_hash')->get() as $bidder) {
            $this->send($bidder, $tender);
        }
    }

    private function send(Bidder $bidder, Tender $tender): void
    {
        $token = $this->auth->issueLink($bidder, $tender);
        $this->notices->linkSent($bidder, $tender, $token);
        $this->auditor->record('procurement.portal.link_sent', $bidder, null, ['tender' => $tender->id, 'email' => $bidder->email]);
    }

    /** @param array<string, mixed> $values */
    private function create(Tender $tender, array $values): Bidder
    {
        $taken = Bidder::query()->where('tender_id', $tender->id)->whereNull('removed_at')->whereRaw('lower(email) = ?', [$values['email']])->exists();

        if ($taken) {
            throw ProcurementError::make('already_invited', field: 'email');
        }

        return Bidder::query()->create(['tender_id' => $tender->id, ...$values]);
    }

    private function assertOpenForBidders(Tender $tender): void
    {
        if (! in_array($tender->status, [Tender::DRAFT, Tender::PUBLISHED], true) || ($tender->status === Tender::PUBLISHED && $tender->closedForBids())) {
            throw ProcurementError::make('tender_not_open_for_bids', status: 409);
        }
    }

    /** For tests and seeding tools: a fresh link for a bidder, returned (the email carries the same). */
    public function issueLinkForTesting(Bidder $bidder): string
    {
        return Tx::run(fn () => $this->auth->issueLink($bidder, Tender::query()->findOrFail($bidder->tender_id)));
    }
}
