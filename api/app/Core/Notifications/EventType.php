<?php

namespace App\Core\Notifications;

use InvalidArgumentException;

/**
 * A kind of notification a module sends (NOT-02), e.g.
 * `core.approval.requested`. Registered once at boot through
 * EventTypes::register().
 *
 * - `placeholders`: name => sample value. The names are what templates may
 *   use as `{name}` (NOT-03); the samples fill the preview. Every event
 *   also has the common placeholders (EventTypes::COMMON).
 * - `defaultChannels`: on for a user who changed nothing (NOT-04).
 * - `channels`: the channels it may go out on at all.
 * - `mandatoryAllowed`: whether a tenant admin may make channels of it
 *   mandatory (NOT-04), e.g. approvals.
 * - Default texts live in the language files under `langKey`
 *   (default `notifications.events.<key>`): `label`, `subject`, `body` and
 *   optionally `sms` (the short text for SMS and WhatsApp).
 */
final class EventType
{
    private const KEY = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    private const PLACEHOLDER = '/^[a-z][a-z0-9_]*$/';

    public readonly string $module;

    public readonly string $langKey;

    /**
     * @param  array<string, string>  $placeholders  name => sample value
     * @param  list<string>  $defaultChannels
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly string $key,
        public readonly array $placeholders = [],
        public readonly array $defaultChannels = [Channels::IN_APP, Channels::EMAIL],
        public readonly array $channels = Channels::ALL,
        public readonly bool $mandatoryAllowed = false,
        ?string $langKey = null,
    ) {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException("Invalid notification event type [{$key}]: use module.resource.event.");
        }

        foreach (array_keys($placeholders) as $name) {
            if (! is_string($name) || preg_match(self::PLACEHOLDER, $name) !== 1) {
                throw new InvalidArgumentException("Invalid placeholder [{$name}] for [{$key}].");
            }
        }

        if (array_diff($channels, Channels::ALL) !== [] || array_diff($defaultChannels, $channels) !== []) {
            throw new InvalidArgumentException("Unknown or unavailable default channel for [{$key}].");
        }

        $this->module = strstr($key, '.', true);
        $this->langKey = $langKey ?? "notifications.events.{$key}";
    }

    /** @return list<string> the placeholder names a template of this event may use, common ones included */
    public function placeholderNames(): array
    {
        return array_values(array_unique([...array_keys($this->placeholders), ...EventTypes::COMMON]));
    }

    /** @return array<string, string> sample values for a preview */
    public function samples(): array
    {
        return [
            ...$this->placeholders,
            'recipient_name' => __('notifications.samples.recipient_name'),
            'app_name' => (string) config('app.name'),
        ];
    }

    public function label(): string
    {
        return __("{$this->langKey}.label");
    }
}
