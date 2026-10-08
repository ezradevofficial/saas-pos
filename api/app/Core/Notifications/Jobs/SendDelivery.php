<?php

namespace App\Core\Notifications\Jobs;

use App\Core\Notifications\Channels;
use App\Core\Notifications\Drivers\ChannelDrivers;
use App\Core\Notifications\Drivers\OutgoingMessage;
use App\Core\Notifications\Mail\NotificationMail;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * NOT-01, NOT-06: send one queued delivery (email through Laravel Mail,
 * push, SMS and WhatsApp through their ChannelDriver), inside the
 * delivery's tenant (TenantAware: row-level security means a job for
 * tenant A never reads tenant B's rows).
 *
 * A failed attempt is recorded (attempts, error) and the job queues
 * itself again after the configured backoff; after the last attempt the
 * delivery is `failed`. Retries are this job's own, so they behave the
 * same on the sync queue (tests) as under the workers. A delivery that is
 * no longer `queued` (already sent by a duplicate job) is left alone.
 */
class SendDelivery implements ShouldQueue
{
    use Dispatchable, Queueable;

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
        $delivery = NotificationDelivery::query()->whereKey($this->deliveryId)->first();

        if ($delivery === null || $delivery->status !== NotificationDelivery::QUEUED) {
            return;
        }

        $delivery->attempts++;

        try {
            if ($delivery->channel === Channels::EMAIL) {
                Mail::to($delivery->recipient)->send(new NotificationMail(
                    (string) $delivery->subject, $delivery->body, $delivery->link, $delivery->locale,
                ));
                $delivery->fill(['status' => NotificationDelivery::SENT, 'sent_at' => now()]);
            } else {
                $driver = $drivers->for($delivery->channel);

                if ($driver === null) {
                    // The driver was removed after the delivery was queued.
                    $delivery->fill(['status' => NotificationDelivery::SKIPPED, 'reason' => NotificationDelivery::REASON_CHANNEL_UNAVAILABLE])->save();

                    return;
                }

                $result = $driver->send(new OutgoingMessage(
                    $delivery->id, $delivery->channel, (string) $delivery->recipient, $delivery->subject, $delivery->body, $delivery->link,
                ));
                $delivery->fill([
                    'status' => $result->delivered ? NotificationDelivery::DELIVERED : NotificationDelivery::SENT,
                    'provider_message_id' => $result->providerMessageId,
                    'sent_at' => now(),
                    'delivered_at' => $result->delivered ? now() : null,
                ]);
            }

            $delivery->fill(['error' => null, 'next_attempt_at' => null])->save();
        } catch (Throwable $e) {
            $this->recordFailure($delivery, $e);
        }
    }

    /** A worker crash or timeout: the delivery is failed rather than left queued. */
    public function failed(?Throwable $e): void
    {
        app(TenantContext::class)->run($this->tenantId, function () use ($e) {
            NotificationDelivery::query()->whereKey($this->deliveryId)->where('status', NotificationDelivery::QUEUED)->update([
                'status' => NotificationDelivery::FAILED,
                'failed_at' => now(),
                'error' => mb_substr($e?->getMessage() ?? 'The job failed.', 0, 1000),
            ]);
        });
    }

    private function recordFailure(NotificationDelivery $delivery, Throwable $e): void
    {
        $max = (int) config('notifications.attempts', 3);
        $error = mb_substr(class_basename($e).': '.$e->getMessage(), 0, 1000);

        if ($delivery->attempts >= $max) {
            $delivery->fill(['status' => NotificationDelivery::FAILED, 'error' => $error, 'failed_at' => now(), 'next_attempt_at' => null])->save();

            return;
        }

        $backoff = (array) config('notifications.backoff', [60, 300]);
        $delay = (int) ($backoff[$delivery->attempts - 1] ?? end($backoff) ?: 60);
        $delivery->fill(['error' => $error, 'next_attempt_at' => now()->addSeconds($delay)])->save();

        self::dispatch($this->tenantId, $delivery->id)->delay($delay);
    }
}
