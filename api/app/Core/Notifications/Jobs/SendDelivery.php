<?php

namespace App\Core\Notifications\Jobs;

use App\Core\Identity\Models\User;
use App\Core\Notifications\Channels;
use App\Core\Notifications\Drivers\ChannelDrivers;
use App\Core\Notifications\Drivers\DriverResult;
use App\Core\Notifications\Drivers\OutgoingMessage;
use App\Core\Notifications\Mail\MailActions;
use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
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
 */
class SendDelivery implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const CLAIM_TIMEOUT_MINUTES = 15;

    /** Driver errors are retried by handle(); this covers a worker crash. */
    public int $tries = 1;

    public function __construct(
        public string $tenantId,
        public string $deliveryId,
    ) {
        $this->onQueue(config('notifications.queue'));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(ChannelDrivers $drivers): void
    {
        if (! $this->claim()) {
            return;
        }

        $delivery = NotificationDelivery::query()->findOrFail($this->deliveryId);
        $skip = $this->skipReason($delivery, $drivers);

        if ($skip !== null) {
            $delivery->fill(['status' => NotificationDelivery::SKIPPED, 'reason' => $skip, 'next_attempt_at' => null])->save();

            return;
        }

        try {
            $result = $this->handOver($delivery, $drivers);
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

    private function skipReason(NotificationDelivery $delivery, ChannelDrivers $drivers): ?string
    {
        $user = User::query()->find($delivery->user_id);

        if ($user === null || ! $user->isActive()) {
            return NotificationDelivery::REASON_USER_DEACTIVATED;
        }

        return match ($delivery->channel) {
            Channels::EMAIL => $user->email !== null && $user->email_verified_at !== null && $user->email === $delivery->recipient
                ? null : NotificationDelivery::REASON_NO_EMAIL,
            Channels::SMS, Channels::WHATSAPP => $user->phone !== null && $user->phone_verified_at !== null && $user->phone === $delivery->recipient
                ? ($drivers->for($delivery->channel) === null ? NotificationDelivery::REASON_CHANNEL_UNAVAILABLE : null)
                : NotificationDelivery::REASON_NO_PHONE,
            // The driver was removed after the delivery was queued.
            default => $drivers->for($delivery->channel) === null ? NotificationDelivery::REASON_CHANNEL_UNAVAILABLE : null,
        };
    }

    /** The only retryable step: the mailer or the provider. */
    private function handOver(NotificationDelivery $delivery, ChannelDrivers $drivers): DriverResult
    {
        if ($delivery->channel === Channels::EMAIL) {
            // Buttons such as single-use approve links are made now, never stored (MailActions).
            Mail::to($delivery->recipient)->send(new NotificationMail(
                (string) $delivery->subject, $delivery->body, $delivery->link, $delivery->locale, app(MailActions::class)->for($delivery),
            ));

            return new DriverResult;
        }

        return $drivers->for($delivery->channel)->send(new OutgoingMessage(
            $delivery->id, $delivery->channel, (string) $delivery->recipient, $delivery->subject, $delivery->body, $delivery->link,
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

        self::dispatch($this->tenantId, $delivery->id)->delay($delay);
    }
}
