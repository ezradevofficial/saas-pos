<?php

namespace App\Core\Notifications\Drivers;

use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\Sms\SmsSender;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

/**
 * NOT-01: the provider adapter of each driven channel (push, SMS,
 * WhatsApp), chosen by `notifications.drivers.<channel>`. Without a
 * driver the channel is unavailable: deliveries on it are skipped with
 * the reason `channel_unavailable`. In local and testing an unset driver
 * means `fake`; `none` switches a channel off anywhere. System event
 * types (ADR 009) send SMS through the platform SMS sender when the
 * channel has no driver.
 */
class ChannelDrivers
{
    /** @var array<string, ChannelDriver> */
    private array $resolved = [];

    public function __construct(private readonly Application $app) {}

    public function available(string $channel, ?EventType $type = null): bool
    {
        return $this->driverName($channel) !== null || $this->platformSms($channel, $type);
    }

    /**
     * The driver of $channel, or null when the channel is unavailable. A
     * system event type's SMS falls back to the platform SMS sender
     * (ADR 009).
     */
    public function for(string $channel, ?EventType $type = null): ?ChannelDriver
    {
        $name = $this->driverName($channel);

        if ($name === null) {
            return $this->platformSms($channel, $type) ? new SmsSenderDriver($this->app->make(SmsSender::class)) : null;
        }

        return $this->resolved["{$channel}|{$name}"] ??= match ($name) {
            'fake' => new FakeChannelDriver($channel),
            default => throw new InvalidArgumentException("Unknown notification driver [{$name}] for [{$channel}]."),
        };
    }

    /** Replace a channel's driver (tests, or a provider package). */
    public function extend(string $channel, ChannelDriver $driver): void
    {
        $this->resolved["{$channel}|".$this->driverName($channel)] = $driver;
    }

    private function platformSms(string $channel, ?EventType $type): bool
    {
        return $channel === Channels::SMS && $type !== null && $type->system;
    }

    private function driverName(string $channel): ?string
    {
        if (! in_array($channel, Channels::DRIVEN, true)) {
            return null;
        }

        $name = config("notifications.drivers.{$channel}");

        if ($name === null || $name === '') {
            return $this->app->environment(['local', 'testing']) ? 'fake' : null;
        }

        return $name === 'none' ? null : (string) $name;
    }
}
