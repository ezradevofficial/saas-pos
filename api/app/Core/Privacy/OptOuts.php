<?php

namespace App\Core\Privacy;

use App\Core\MasterData\Parties\Party;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Privacy\Contracts\ReceivesOptOutReplies;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * NFR-08 (KE DPA): opting out of marketing messages, without signing in.
 *
 * - Email: each marketing email carries an unsubscribe link (the web page
 *   `/opt-out/{token}`) and `List-Unsubscribe` headers (one click, RFC 8058,
 *   to `POST /api/v1/opt-out/{token}`). The token (OptOutTokens) names the
 *   tenant and the delivery; the delivery names the customer and the
 *   channel. Using it withdraws consent on that channel once: the delivery
 *   keeps `opted_out_at`, and a second use records nothing.
 * - SMS and WhatsApp: the message ends with "Reply STOP to opt out"; a
 *   reply that is an opt-out keyword withdraws marketing consent on every
 *   channel (an `any` refusal) for the customers with that number who
 *   still consent on any channel (ReceivesOptOutReplies; owner decision
 *   2026-10-22).
 *
 * Every withdrawal goes through Consents (audited) with evidence naming
 * the channel and where it came from, never the message.
 */
final class OptOuts implements ReceivesOptOutReplies
{
    /** How long an unsubscribe link works after its email was sent. */
    public const LINK_DAYS = 400;

    public function __construct(
        private readonly OptOutTokens $tokens,
        private readonly Consents $consents,
        private readonly TenantContext $tenants,
    ) {}

    /** The web page of a marketing email's unsubscribe link, in the delivery's language. */
    public function pageUrl(string $tenantId, NotificationDelivery $delivery): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/opt-out/'.$this->tokens->make($tenantId, $delivery->id).'?lang='.$delivery->locale;
    }

    /** The one-click endpoint for the `List-Unsubscribe` header (RFC 8058). */
    public function oneClickUrl(string $tenantId, NotificationDelivery $delivery): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/opt-out/'.$this->tokens->make($tenantId, $delivery->id);
    }

    /**
     * The state of a link: `active` (consent can be withdrawn), `done` (the
     * link was used, or the customer no longer consents), with the
     * delivery's channel and language; null for a bad, foreign or expired token.
     *
     * @return array{status: string, channel: string, locale: string}|null
     */
    public function linkState(string $token): ?array
    {
        return $this->withDelivery($token, function (NotificationDelivery $delivery) {
            $active = $delivery->opted_out_at === null
                && $this->consents->allows('customer', (string) $delivery->consent_subject_id, Consents::MARKETING, $delivery->channel);

            return ['status' => $active ? 'active' : 'done', 'channel' => $delivery->channel, 'locale' => $delivery->locale];
        }, false);
    }

    /**
     * Use a link: withdraw consent on the delivery's channel, once.
     *
     * @return array{status: string, channel: string, locale: string}|null `withdrawn` or `already`; null as linkState
     */
    public function useLink(string $token): ?array
    {
        return $this->withDelivery($token, function (NotificationDelivery $delivery) {
            if ($delivery->opted_out_at !== null) {
                return ['status' => 'already', 'channel' => $delivery->channel, 'locale' => $delivery->locale];
            }

            $party = (string) $delivery->consent_subject_id;

            if ($this->consents->allows('customer', $party, Consents::MARKETING, $delivery->channel)) {
                $this->consents->record(null, 'customer', $party, Consents::MARKETING, $delivery->channel, false, 'opt_out_link',
                    "Used the unsubscribe link of a marketing {$delivery->channel} message (delivery {$delivery->id}).");
            }

            $delivery->forceFill(['opted_out_at' => CarbonImmutable::now()])->save();

            return ['status' => 'withdrawn', 'channel' => $delivery->channel, 'locale' => $delivery->locale];
        }, true);
    }

    public function isOptOut(string $text): bool
    {
        $word = mb_strtoupper(trim(preg_replace('/[\s.!]+/u', ' ', $text) ?? ''));

        return in_array($word, array_map('mb_strtoupper', (array) config('privacy.opt_out_keywords')), true);
    }

    public function optOut(string $channel, string $phone, string $via): int
    {
        $this->tenants->require();

        if (! in_array($channel, [Channels::SMS, Channels::WHATSAPP], true) || preg_match('/^\+[1-9]\d{6,14}$/', $phone) !== 1) {
            return 0;
        }

        $parties = Party::query()->whereNull('archived_at')->whereRaw("'customer' = any(roles)")
            ->whereRaw('phones @> ?::jsonb', [json_encode([['number' => $phone]])])->pluck('id')->map(fn ($id) => (string) $id)->all();
        // Owner decision 2026-10-22: a STOP withdraws marketing on every channel, not only the one it came by.
        $consenting = [];
        foreach (EventType::MARKETING_CHANNELS as $any) {
            foreach ($this->consents->allowedAmong('customer', $parties, Consents::MARKETING, $any) as $party) {
                $consenting[$party] = true;
            }
        }

        foreach (array_keys($consenting) as $party) {
            $this->consents->record(null, 'customer', (string) $party, Consents::MARKETING, 'any', false, 'opt_out_reply',
                "Replied with an opt-out keyword by {$channel} ({$via}); withdrawn on every channel.");
        }

        return count($consenting);
    }

    /**
     * Run $fn on the token's marketing delivery inside its tenant (row-level
     * security), in one transaction; null when the token is bad or foreign,
     * the delivery is not a marketing one, or the link has expired.
     *
     * @template T
     *
     * @param  callable(NotificationDelivery): T  $fn
     * @return T|null
     */
    private function withDelivery(string $token, callable $fn, bool $lock): mixed
    {
        $ids = $this->tokens->read($token);

        if ($ids === null) {
            return null;
        }

        return $this->tenants->run($ids['tenant'], fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($ids, $fn, $lock) {
            $delivery = NotificationDelivery::query()->whereKey($ids['delivery'])->whereNotNull('consent_subject_id')
                ->when($lock, fn ($query) => $query->lockForUpdate())->first();

            if ($delivery === null || $delivery->created_at->lessThan(CarbonImmutable::now()->subDays(self::LINK_DAYS))) {
                return null;
            }

            return $fn($delivery);
        }));
    }
}
