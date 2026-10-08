<?php

namespace App\Core\Notifications\Http\Resources;

use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Models\InAppNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InAppNotification */
class InAppNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $types = app(EventTypes::class);

        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'event_label' => $types->has($this->event_type) ? $types->get($this->event_type)->label() : $this->event_type,
            'subject' => $this->subject,
            'body' => $this->body,
            'link' => $this->link,
            'read_at' => $this->read_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
