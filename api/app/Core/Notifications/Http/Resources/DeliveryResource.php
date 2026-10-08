<?php

namespace App\Core\Notifications\Http\Resources;

use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Models\NotificationDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * NOT-06: one delivery for the admin list. The message body is left out
 * (it may carry personal details); the subject identifies the message.
 *
 * @mixin NotificationDelivery
 */
class DeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $types = app(EventTypes::class);

        return [
            'id' => $this->id,
            'user' => $this->whenLoaded('user', fn () => ['id' => $this->user->id, 'name' => $this->user->name]),
            'user_id' => $this->user_id,
            'event_type' => $this->event_type,
            'event_label' => match (true) {
                $this->event_type === NotificationDelivery::DIGEST_EVENT => __('notifications.digests.'.($this->digest ?? 'daily')),
                $types->has($this->event_type) => $types->get($this->event_type)->label(),
                default => $this->event_type,
            },
            'channel' => $this->channel,
            'status' => $this->status,
            'reason' => $this->reason,
            'reason_label' => $this->reason === null ? null : __('notifications.reasons.'.$this->reason),
            'recipient' => $this->recipient,
            'locale' => $this->locale,
            'subject' => $this->subject,
            'digest' => $this->digest,
            'digest_id' => $this->digest_id,
            'attempts' => $this->attempts,
            // A safe code; the raw provider error is only in the log.
            'error' => $this->error,
            'error_label' => $this->error === null ? null : __('notifications.delivery_errors.'.$this->error),
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
