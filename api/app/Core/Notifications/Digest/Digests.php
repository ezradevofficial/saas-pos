<?php

namespace App\Core\Notifications\Digest;

use App\Core\Identity\Models\User;
use App\Core\Notifications\Channels;
use App\Core\Notifications\Jobs\SendDelivery;
use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Notifications\Templates\RenderedMessage;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * NOT-05: one digest email per user and period, in the current tenant.
 *
 * A daily digest goes out at `notifications.digest_hour` in the user's
 * time zone (RecipientTimezone) and carries the user's emails held for it
 * (`pending_digest`, digest `daily`) that were created before that time;
 * a weekly digest the same on Mondays. Emails created after the boundary
 * wait for the next one. The digest is itself an email delivery (event
 * `core.notification.digest`) sent by SendDelivery with the usual
 * retries; the items become `digested` and point at it. Running again in
 * the same period finds nothing left, so the hourly schedule is safe.
 */
class Digests
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly RecipientTimezone $timezones,
    ) {}

    /** @return int the number of digests queued */
    public function sendDue(CarbonInterface $now): int
    {
        $tenantId = $this->tenants->require();
        $now = CarbonImmutable::instance($now);
        $queued = 0;

        $pending = NotificationDelivery::query()
            ->where('status', NotificationDelivery::PENDING_DIGEST)
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->groupBy('user_id');

        if ($pending->isEmpty()) {
            return 0;
        }

        $users = User::query()->whereIn('id', $pending->keys())->get()->keyBy('id');

        foreach ($pending as $userId => $items) {
            $user = $users->get($userId);

            if ($user === null) {
                continue;
            }

            $zone = $this->timezones->for($user);

            foreach ([Channels::DIGEST_DAILY, Channels::DIGEST_WEEKLY] as $period) {
                $boundary = self::lastBoundary($now, $zone, $period);
                $due = $items->filter(fn (NotificationDelivery $item) => $item->digest === $period && $item->created_at->lt($boundary))->values();

                if ($due->isNotEmpty()) {
                    $this->queueDigest($tenantId, $user, $period, $due, $zone);
                    $queued++;
                }
            }
        }

        return $queued;
    }

    /** The latest daily (or Monday, weekly) digest time at or before $now, in $zone, as UTC. */
    public static function lastBoundary(CarbonInterface $now, string $zone, string $period): CarbonImmutable
    {
        $local = CarbonImmutable::instance($now)->setTimezone($zone);
        $boundary = $local->setTime((int) config('notifications.digest_hour', 7), 0);

        if ($period === Channels::DIGEST_WEEKLY) {
            $boundary = $boundary->startOfWeek(CarbonInterface::MONDAY)->setTime((int) config('notifications.digest_hour', 7), 0);

            if ($boundary->gt($local)) {
                $boundary = $boundary->subWeek();
            }
        } elseif ($boundary->gt($local)) {
            $boundary = $boundary->subDay();
        }

        return $boundary->utc();
    }

    /** @param Collection<int, NotificationDelivery> $items */
    private function queueDigest(string $tenantId, User $user, string $period, Collection $items, string $zone): void
    {
        $locale = in_array($user->locale, Channels::LOCALES, true) ? $user->locale : 'en';
        $message = $this->render($period, $items, $locale, $zone);
        $email = $user->email !== null && $user->email_verified_at !== null ? $user->email : null;

        DB::transaction(function () use ($tenantId, $user, $period, $items, $locale, $message, $email) {
            $digest = NotificationDelivery::create([
                'user_id' => $user->id,
                'event_type' => NotificationDelivery::DIGEST_EVENT,
                'channel' => Channels::EMAIL,
                'locale' => $locale,
                'subject' => $message->subject,
                'body' => $message->body,
                'digest' => $period,
                'recipient' => $email,
                // The address may have gone since the items were held.
                'status' => $email === null ? NotificationDelivery::SKIPPED : NotificationDelivery::QUEUED,
                'reason' => $email === null ? NotificationDelivery::REASON_NO_EMAIL : null,
            ]);

            NotificationDelivery::query()
                ->whereIn('id', $items->modelKeys())
                ->where('status', NotificationDelivery::PENDING_DIGEST)
                ->update(['status' => NotificationDelivery::DIGESTED, 'digest_id' => $digest->id, 'updated_at' => now()]);

            if ($digest->status === NotificationDelivery::QUEUED) {
                SendDelivery::dispatch($tenantId, $digest->id)->afterCommit();
            }
        });
    }

    /** @param Collection<int, NotificationDelivery> $items */
    private function render(string $period, Collection $items, string $locale, string $zone): RenderedMessage
    {
        $lines = $items->map(function (NotificationDelivery $item) use ($locale, $zone) {
            $at = $item->created_at->copy()->setTimezone($zone)->locale($locale)->isoFormat('D MMM HH:mm');
            $line = "{$at}  {$item->subject}";

            return $item->link === null ? $line : $line."\n    ".NotificationMail::absolute($item->link);
        })->implode("\n\n");

        $count = $items->count();

        return new RenderedMessage(
            trans_choice("notifications.digest.subject_{$period}", $count, ['count' => $count, 'app' => config('app.name')], $locale),
            __('notifications.digest.intro', [], $locale)."\n\n".$lines,
        );
    }
}
