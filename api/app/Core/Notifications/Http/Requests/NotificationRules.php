<?php

namespace App\Core\Notifications\Http\Requests;

use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Templates\TemplateRenderer;
use Closure;

/** Validation shared by the notification requests (NOT-02..NOT-04). */
trait NotificationRules
{
    /** An event type of the core or of a module the tenant has active. */
    protected function eventTypeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) || ! app(EventTypes::class)->isActive($value)) {
                $fail(__('notifications.errors.unknown_event_type', ['event' => is_string($value) ? mb_substr($value, 0, 100) : '']));
            }
        };
    }

    /** The event type named by $field, when it is a valid one. */
    protected function eventTypeOf(mixed $key): ?EventType
    {
        $types = app(EventTypes::class);

        return is_string($key) && $types->isActive($key) ? $types->get($key) : null;
    }

    /** $channel is one $type goes out on (`all` too when $allowAll). */
    protected function channelRule(?EventType $type, bool $allowAll = false): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($type, $allowAll) {
            if ($type === null || ($allowAll && $value === Channels::ANY)) {
                return;
            }

            if (! is_string($value) || ! in_array($value, $type->channels, true)) {
                $fail(__('notifications.errors.channel_not_offered', [
                    'event' => $type->label(),
                    'channel' => is_string($value) && in_array($value, Channels::ALL, true) ? __("notifications.channels.{$value}") : (is_string($value) ? mb_substr($value, 0, 30) : ''),
                ]));
            }
        };
    }

    /** NOT-03: only the event type's placeholders (and the common ones). */
    protected function placeholdersRule(?EventType $type): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($type) {
            if ($type === null || ! is_string($value)) {
                return;
            }

            $unknown = TemplateRenderer::unknown($value, $type->placeholderNames());

            if ($unknown !== []) {
                $fail(__('notifications.errors.unknown_placeholders', [
                    'placeholders' => implode(', ', array_map(fn ($name) => '{'.$name.'}', $unknown)),
                    'allowed' => implode(', ', array_map(fn ($name) => '{'.$name.'}', $type->placeholderNames())),
                ]));
            }
        };
    }

    /** @return array<string, string> */
    protected function notificationAttributes(): array
    {
        return array_map(fn ($key) => __("notifications.attributes.{$key}"), [
            'event_type' => 'event_type',
            'channel' => 'channel',
            'locale' => 'locale',
            'subject' => 'subject',
            'body' => 'body',
        ]);
    }
}
