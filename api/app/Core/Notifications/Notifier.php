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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
 * channels (Preferences), the text in their language from the tenant's
 * templates or the defaults (Templates), then one delivery per channel
 * (NOT-06). In-app is written at once; email goes out through a queued
 * SendDelivery job, or waits for the user's digest (NOT-05); push, SMS and
 * WhatsApp go through their driver's queued job, or are skipped with a
 * reason when the user has no contact for them or no driver is configured.
 * Jobs are dispatched after the surrounding transaction commits.
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

        if ($event->recipientIds === []) {
            return collect();
        }

        // Row-level security: ids of another tenant's users find nothing.
        $users = User::query()
            ->whereIn('id', array_values(array_filter($event->recipientIds, Str::isUuid(...))))
            ->where('status', '!=', User::STATUS_DEACTIVATED)
            ->get();

        $overrides = $this->templates->overrides($type);
        $mandatory = $this->preferences->mandatoryChannels($type);
        $preferences = NotificationPreference::query()
            ->where('event_type', $type->key)->whereIn('user_id', $users->modelKeys())->get()->keyBy('user_id');

        return DB::transaction(function () use ($event, $type, $users, $overrides, $mandatory, $preferences, $tenantId) {
            $deliveries = collect();

            foreach ($users as $user) {
                $choice = $this->preferences->resolve($user, $type, $preferences->get($user->id), $mandatory);
                $locale = in_array($user->locale, Channels::LOCALES, true) ? $user->locale : 'en';
                $values = [
                    ...array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $event->data),
                    'recipient_name' => $user->name,
                    'app_name' => (string) config('app.name'),
                ];

                foreach ($choice['channels'] as $channel => $state) {
                    if (! $state['enabled']) {
                        continue;
                    }

                    $message = $this->templates->effective($type, $channel, $locale, $overrides)->render($values);
                    $row = [
                        'user_id' => $user->id,
                        'event_type' => $type->key,
                        'channel' => $channel,
                        'locale' => $locale,
                        'subject' => $message->subject,
                        'body' => $message->body,
                        'link' => $event->link,
                    ];

                    $delivery = match ($channel) {
                        Channels::IN_APP => $this->inApp($row, $event),
                        Channels::EMAIL => $this->email($row, $user, $choice['digest']),
                        default => $this->driven($row, $user, $channel),
                    };

                    if ($delivery->status === NotificationDelivery::QUEUED) {
                        SendDelivery::dispatch($tenantId, $delivery->id)->afterCommit();
                    }

                    $deliveries->push($delivery);
                }
            }

            return $deliveries;
        });
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
    private function driven(array $row, User $user, string $channel): NotificationDelivery
    {
        if (! $this->drivers->available($channel)) {
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
