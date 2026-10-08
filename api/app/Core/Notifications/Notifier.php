<?php

namespace App\Core\Notifications;

use App\Core\Identity\Models\User;
use App\Core\Notifications\Drivers\ChannelDrivers;
use App\Core\Notifications\Jobs\SendDelivery;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Notifications\Models\NotificationPreference;
use App\Core\Notifications\Templates\Templates;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * NOT-02: the one service every module sends notifications through; no
 * module sends email, SMS or push itself.
 *
 *     app(Notifier::class)->send(new NotificationEvent(
 *         'core.approval.requested', [$approver], ['document_number' => 'PO-0042'], '/approvals/…',
 *     ));
 *
 * In the current tenant, for each recipient (an active user of the
 * tenant): the channels from their preferences and the tenant's mandatory
 * channels (Preferences), the text from the tenant's templates (one text,
 * the same for everyone) or else the default in their language
 * (Templates), then one delivery per channel
 * (NOT-06). In-app is written at once; email goes out through a queued
 * SendDelivery job, or waits for the user's digest (NOT-05); push, SMS and
 * WhatsApp go through their driver's queued job, or are skipped with a
 * reason when the user has no contact for them or no driver is configured.
 * Jobs are dispatched after the surrounding transaction commits.
 *
 * System event types (ADR 009) may also go to contacts that are not users
 * yet (NotificationAddress, e.g. an invitation) and carry secrets (e.g. the
 * invitation link) that are never stored: they travel in the encrypted
 * SendDelivery job only. One-time codes never go through here (ADR 009).
 */
class Notifier
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly EventTypes $types,
        private readonly Preferences $preferences,
        private readonly Templates $templates,
        private readonly ChannelDrivers $drivers,
    ) {}

    /** @return Collection<int, NotificationDelivery> the deliveries created, every recipient and channel */
    public function send(NotificationEvent $event): Collection
    {
        $tenantId = $this->tenants->require();
        $type = $this->types->get($event->type);
        self::assertSystemOnly($type, $event);
        $link = self::safeLink($event->link, $type->key);

        if ($event->recipientIds === [] && $event->addresses === []) {
            return collect();
        }

        // Row-level security: ids of another tenant's users find nothing.
        $users = $event->recipientIds === [] ? new EloquentCollection : User::query()
            ->whereIn('id', array_values(array_filter($event->recipientIds, Str::isUuid(...))))
            ->where('status', User::STATUS_ACTIVE)
            ->get();

        $overrides = $this->templates->overrides($type);
        $mandatory = $this->preferences->mandatoryChannels($type);
        $preferences = NotificationPreference::query()
            ->where('event_type', $type->key)->whereIn('user_id', $users->modelKeys())->get()->keyBy('user_id');
        // Secret placeholders stay `{name}` in the stored text (ADR 009).
        $markers = [];
        foreach ($type->secrets as $name) {
            $markers[$name] = '{'.$name.'}';
        }

        return DB::transaction(function () use ($event, $type, $users, $overrides, $mandatory, $preferences, $tenantId, $link, $markers) {
            $deliveries = collect();
            $queue = function (NotificationDelivery $delivery) use ($tenantId, $event, $deliveries) {
                if ($delivery->status === NotificationDelivery::QUEUED) {
                    SendDelivery::dispatch($tenantId, $delivery->id, $event->secrets)->afterCommit();
                }

                $deliveries->push($delivery);
            };

            foreach ($users as $user) {
                $choice = $this->preferences->resolve($user, $type, $preferences->get($user->id), $mandatory);
                $locale = in_array($user->locale, Channels::LOCALES, true) ? $user->locale : 'en';
                $values = [
                    ...array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $event->data),
                    'recipient_name' => $user->name,
                    'app_name' => (string) config('app.name'),
                    ...$markers,
                ];
                $channels = array_keys(array_filter($choice['channels'], fn (array $state) => $state['enabled']));

                // A system message reaches a user without a verified email
                // by SMS instead (ADR 009).
                if ($type->system && in_array(Channels::EMAIL, $channels, true) && ! in_array(Channels::SMS, $channels, true)
                    && in_array(Channels::SMS, $type->channels, true) && ($user->email === null || $user->email_verified_at === null)) {
                    $channels[] = Channels::SMS;
                }

                foreach ($channels as $channel) {
                    $message = $this->templates->effective($type, $channel, $locale, $overrides)->render($values, $channel);
                    $row = [
                        'user_id' => $user->id,
                        'event_type' => $type->key,
                        'channel' => $channel,
                        'locale' => $locale,
                        'subject' => $message->subject,
                        'body' => $message->body,
                        'link' => $link,
                    ];

                    $queue(match ($channel) {
                        Channels::IN_APP => $this->inApp($row, $event),
                        Channels::EMAIL => $this->email($row, $user, $choice['digest']),
                        default => $this->driven($row, $user, $channel, $type),
                    });
                }
            }

            foreach ($event->addresses as $address) {
                $locale = in_array($address->locale, Channels::LOCALES, true) ? $address->locale : 'en';
                $values = [
                    ...array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $event->data),
                    'recipient_name' => $address->name,
                    'app_name' => (string) config('app.name'),
                    ...$markers,
                ];
                $message = $this->templates->effective($type, $address->channel, $locale, $overrides)->render($values, $address->channel);
                $row = [
                    'user_id' => null,
                    'event_type' => $type->key,
                    'channel' => $address->channel,
                    'locale' => $locale,
                    'subject' => $message->subject,
                    'body' => $message->body,
                    'link' => $link,
                    'recipient' => $address->to,
                ];

                $queue($address->channel === Channels::SMS && ! $this->drivers->available(Channels::SMS, $type)
                    ? $this->skipped($row, NotificationDelivery::REASON_CHANNEL_UNAVAILABLE)
                    : NotificationDelivery::create([...$row, 'status' => NotificationDelivery::QUEUED]));
            }

            return $deliveries;
        });
    }

    /** Addresses and secrets are for system event types only, and secrets only for the type's secret placeholders. */
    private static function assertSystemOnly(EventType $type, NotificationEvent $event): void
    {
        if (! $type->system && ($event->addresses !== [] || $event->secrets !== [])) {
            throw new InvalidArgumentException("[{$type->key}] is not a system event type: it reaches users only and has no secrets.");
        }

        if (array_diff(array_keys($event->secrets), $type->secrets) !== []) {
            throw new InvalidArgumentException("[{$type->key}] has no such secret placeholder.");
        }
    }

    /**
     * Links are paths in the web app only (`/approvals/…`): an absolute or
     * protocol-relative URL never reaches a platform email or the inbox. A
     * refused link is dropped and logged; the notification still goes out.
     */
    public static function safeLink(?string $link, string $eventType = ''): ?string
    {
        if ($link === null || $link === '') {
            return null;
        }

        if (preg_match('#^/(?![/\\\\])[^\s\\\\]*$#', $link) === 1) {
            return $link;
        }

        Log::warning('Notification link dropped: only relative app paths are allowed', ['event_type' => $eventType, 'link' => mb_substr($link, 0, 200)]);

        return null;
    }

    /** In-app: in the inbox at once, delivered (NOT-01). */
    private function inApp(array $row, NotificationEvent $event): NotificationDelivery
    {
        $notification = InAppNotification::create([
            'user_id' => $row['user_id'],
            'event_type' => $row['event_type'],
            'subject' => (string) $row['subject'],
            'body' => $row['body'],
            'link' => $row['link'],
            'data' => $event->data,
        ]);

        return NotificationDelivery::create([
            ...$row,
            'notification_id' => $notification->id,
            'recipient' => $row['user_id'],
            'status' => NotificationDelivery::DELIVERED,
            'sent_at' => now(),
            'delivered_at' => now(),
        ]);
    }

    /** Email to a verified address, now or in the user's digest (NOT-05). */
    private function email(array $row, User $user, string $digest): NotificationDelivery
    {
        if ($user->email === null || $user->email_verified_at === null) {
            return $this->skipped($row, NotificationDelivery::REASON_NO_EMAIL);
        }

        $later = $digest !== Channels::DIGEST_IMMEDIATE;

        return NotificationDelivery::create([
            ...$row,
            'recipient' => $user->email,
            'status' => $later ? NotificationDelivery::PENDING_DIGEST : NotificationDelivery::QUEUED,
            'digest' => $later ? $digest : null,
        ]);
    }

    /** Push to the user's devices, SMS and WhatsApp to a verified phone, through the channel's driver. */
    private function driven(array $row, User $user, string $channel, EventType $type): NotificationDelivery
    {
        if (! $this->drivers->available($channel, $type)) {
            return $this->skipped($row, NotificationDelivery::REASON_CHANNEL_UNAVAILABLE);
        }

        if ($channel === Channels::PUSH) {
            $recipient = $user->id;
        } elseif ($user->phone === null || $user->phone_verified_at === null) {
            return $this->skipped($row, NotificationDelivery::REASON_NO_PHONE);
        } else {
            $recipient = $user->phone;
        }

        return NotificationDelivery::create([...$row, 'recipient' => $recipient, 'status' => NotificationDelivery::QUEUED]);
    }

    private function skipped(array $row, string $reason): NotificationDelivery
    {
        return NotificationDelivery::create([...$row, 'status' => NotificationDelivery::SKIPPED, 'reason' => $reason]);
    }
}
