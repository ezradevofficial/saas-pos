<?php

namespace App\Core\Notifications\Jobs;

use App\Core\Identity\Models\User;
use App\Core\Notifications\Channels;
use App\Core\Notifications\Drivers\ChannelDrivers;
use App\Core\Notifications\Drivers\DriverResult;
use App\Core\Notifications\Drivers\OutgoingMessage;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Mail\MailActions;
use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * NOT-01, NOT-06: send one queued delivery (email through Laravel Mail,
 * push, SMS and WhatsApp through their ChannelDriver), inside the
 * delivery's tenant (TenantAware: row-level security means a job for
 * tenant A never reads tenant B's rows).
 *
 * The delivery is claimed first in one conditional update (queued to
 * sending, attempts + 1), so two jobs never send it twice; a `sending`
 * row older than CLAIM_TIMEOUT_MINUTES (a worker that died mid-send) may
 * be claimed again. The user is read again: a deactivated user, or an
 * address or phone changed or no longer verified since queueing, skips
 * the delivery. Only an exception from the mailer or driver call is
 * retried (after the backoff, up to `notifications.attempts`, then
 * `failed`); anything after a successful hand-over never sends again.
 * The raw error goes to the log; the row keeps a safe code.
 *
 * A delivery to a contact that is not a user (ADR 009) has no user to
 * re-check. Secret placeholders (an invitation link) are filled in from
 * the job's own `secrets` at hand-over, and the job is encrypted on the
 * queue, so the secret is never stored in a table.
 */
class SendDelivery implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, Queueable;

    public const CLAIM_TIMEOUT_MINUTES = 15;

    /** Driver errors are retried by handle(); this covers a worker crash. */
    public int $tries = 1;

    /**
     * @param  array<string, string>  $secrets  values of the event type's secret placeholders (ADR 009):
     *                                          only ever here, in the encrypted job, never in a table
     */
    public function __construct(
        public string $tenantId,
        public string $deliveryId,
        public array $secrets = [],
    ) {
        $this->onQueue(config('notifications.queue'));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(ChannelDrivers $drivers, EventTypes $types): void
    {
        if (! $this->claim()) {
            return;
        }

        $delivery = NotificationDelivery::query()->findOrFail($this->deliveryId);
        $type = $types->has($delivery->event_type) ? $types->get($delivery->event_type) : null;
        $skip = $this->skipReason($delivery, $drivers, $type);

        if ($skip !== null) {
            $delivery->fill(['status' => NotificationDelivery::SKIPPED, 'reason' => $skip, 'next_attempt_at' => null])->save();

            return;
        }

        try {
            $result = $this->handOver($delivery, $drivers, $type);
        } catch (Throwable $e) {
            $this->recordFailure($delivery, $e);

            return;
        }

        $delivery->fill([
            'status' => $result->delivered ? NotificationDelivery::DELIVERED : NotificationDelivery::SENT,
            'provider_message_id' => $result->providerMessageId,
            'sent_at' => now(),
            'delivered_at' => $result->delivered ? now() : null,
            'error' => null,
            'next_attempt_at' => null,
        ])->save();
    }

    /** A worker crash or timeout: the delivery is failed rather than left queued or sending. */
    public function failed(?Throwable $e): void
    {
        Log::warning('Notification delivery job failed', ['delivery_id' => $this->deliveryId, 'error' => $e?->getMessage()]);

        app(TenantContext::class)->run($this->tenantId, function () {
            NotificationDelivery::query()
                ->whereKey($this->deliveryId)
                ->whereIn('status', [NotificationDelivery::QUEUED, NotificationDelivery::SENDING])
                ->update([
                    'status' => NotificationDelivery::FAILED,
                    'failed_at' => now(),
                    'error' => NotificationDelivery::ERROR_JOB_FAILED,
                    'updated_at' => now(),
                ]);
        });
    }

    /** Queued (or stale sending) to sending, attempts + 1, in one statement. */
    private function claim(): bool
    {
        return NotificationDelivery::query()
            ->whereKey($this->deliveryId)
            ->where(fn ($q) => $q->where('status', NotificationDelivery::QUEUED)->orWhere(fn ($q) => $q
                ->where('status', NotificationDelivery::SENDING)
                ->where('updated_at', '<', now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES))))
            ->update([
                'status' => NotificationDelivery::SENDING,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]) === 1;
    }

    private function skipReason(NotificationDelivery $delivery, ChannelDrivers $drivers, ?EventType $type): ?string
    {
        // The message needs a secret the job no longer carries (ADR 009).
        if ($type !== null && array_diff($this->secretsIn($delivery, $type), array_keys($this->secrets)) !== []) {
            return NotificationDelivery::REASON_SECRET_MISSING;
        }

        // A contact that is not a user yet (NotificationAddress): only the channel can be missing.
        if ($delivery->user_id === null) {
            return $drivers->for($delivery->channel, $type) === null && $delivery->channel !== Channels::EMAIL
                ? NotificationDelivery::REASON_CHANNEL_UNAVAILABLE : null;
        }

        $user = User::query()->find($delivery->user_id);

        if ($user === null || ! $user->isActive()) {
            return NotificationDelivery::REASON_USER_DEACTIVATED;
        }

        return match ($delivery->channel) {
            Channels::EMAIL => $user->email !== null && $user->email_verified_at !== null && $user->email === $delivery->recipient
                ? null : NotificationDelivery::REASON_NO_EMAIL,
            Channels::SMS, Channels::WHATSAPP => $user->phone !== null && $user->phone_verified_at !== null && $user->phone === $delivery->recipient
                ? ($drivers->for($delivery->channel, $type) === null ? NotificationDelivery::REASON_CHANNEL_UNAVAILABLE : null)
                : NotificationDelivery::REASON_NO_PHONE,
            // The driver was removed after the delivery was queued.
            default => $drivers->for($delivery->channel, $type) === null ? NotificationDelivery::REASON_CHANNEL_UNAVAILABLE : null,
        };
    }

    /** @return list<string> the secret placeholders $delivery's stored text or link still holds */
    private function secretsIn(NotificationDelivery $delivery, EventType $type): array
    {
        $text = $delivery->subject."\n".$delivery->body."\n".$delivery->link;

        return array_values(array_filter($type->secrets, fn (string $name) => str_contains($text, '{'.$name.'}')));
    }

    /** The stored text with the secret placeholders filled in, at hand-over only. */
    private function reveal(?string $text): ?string
    {
        if ($text === null || $this->secrets === []) {
            return $text;
        }

        $pairs = [];
        foreach ($this->secrets as $name => $value) {
            $pairs['{'.$name.'}'] = (string) $value;
        }

        return strtr($text, $pairs);
    }

    /** The only retryable step: the mailer or the provider. */
    private function handOver(NotificationDelivery $delivery, ChannelDrivers $drivers, ?EventType $type): DriverResult
    {
        if ($delivery->channel === Channels::EMAIL) {
            // Buttons such as single-use approve links are made now, never stored (MailActions).
            Mail::to($delivery->recipient)->send(new NotificationMail(
                (string) $this->reveal($delivery->subject), (string) $this->reveal($delivery->body), $this->reveal($delivery->link),
                $delivery->locale, app(MailActions::class)->for($delivery),
            ));

            return new DriverResult;
        }

        return $drivers->for($delivery->channel, $type)->send(new OutgoingMessage(
            $delivery->id, $delivery->channel, (string) $delivery->recipient, $this->reveal($delivery->subject), (string) $this->reveal($delivery->body), $this->reveal($delivery->link),
        ));
    }

    private function recordFailure(NotificationDelivery $delivery, Throwable $e): void
    {
        Log::warning('Notification delivery attempt failed', [
            'delivery_id' => $delivery->id, 'channel' => $delivery->channel, 'attempt' => $delivery->attempts,
            'error' => $e::class.': '.$e->getMessage(),
        ]);

        $max = (int) config('notifications.attempts', 3);

        if ($delivery->attempts >= $max) {
            $delivery->fill([
                'status' => NotificationDelivery::FAILED, 'error' => NotificationDelivery::ERROR_SEND_FAILED,
                'failed_at' => now(), 'next_attempt_at' => null,
            ])->save();

            return;
        }

        $backoff = (array) config('notifications.backoff', [60, 300]);
        $delay = (int) ($backoff[$delivery->attempts - 1] ?? end($backoff) ?: 60);
        $delivery->fill([
            'status' => NotificationDelivery::QUEUED, 'error' => NotificationDelivery::ERROR_SEND_FAILED,
            'next_attempt_at' => now()->addSeconds($delay),
        ])->save();

        self::dispatch($this->tenantId, $delivery->id, $this->secrets)->delay($delay);
    }
}
