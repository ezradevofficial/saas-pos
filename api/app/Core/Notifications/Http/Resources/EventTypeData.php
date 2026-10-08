<?php

namespace App\Core\Notifications\Http\Resources;

use App\Core\Notifications\Channels;
use App\Core\Notifications\Drivers\ChannelDrivers;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Preferences;

/** NOT-02, NOT-04: an event type as the API shows it, with the tenant's mandatory channels. */
final class EventTypeData
{
    /** @return list<array<string, mixed>> the current tenant's active event types */
    public static function all(): array
    {
        $preferences = app(Preferences::class);
        $settings = $preferences->settings();

        return array_values(array_map(
            fn (EventType $type) => self::make($type, $preferences->mandatoryChannels($type, $settings)),
            app(EventTypes::class)->active(),
        ));
    }

    /**
     * @param  list<string>  $mandatory
     * @return array<string, mixed>
     */
    public static function make(EventType $type, array $mandatory): array
    {
        $drivers = app(ChannelDrivers::class);

        return [
            'event_type' => $type->key,
            'label' => $type->label(),
            'module' => $type->module,
            'channels' => array_map(fn (string $channel) => [
                'channel' => $channel,
                'label' => __("notifications.channels.{$channel}"),
                'default' => in_array($channel, $type->defaultChannels, true),
                'mandatory' => in_array($channel, $mandatory, true),
                // Push, SMS and WhatsApp need a configured provider (NOT-01).
                'available' => ! in_array($channel, Channels::DRIVEN, true) || $drivers->available($channel),
            ], $type->channels),
            'mandatory_allowed' => $type->mandatoryAllowed,
            'mandatory_channels' => $mandatory,
            'placeholders' => array_map(
                fn (string $name) => ['name' => $name, 'sample' => $type->samples()[$name] ?? ''],
                $type->placeholderNames(),
            ),
        ];
    }
}
