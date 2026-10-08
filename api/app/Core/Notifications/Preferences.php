<?php

namespace App\Core\Notifications;

use App\Core\Identity\Models\User;
use App\Core\Notifications\Models\NotificationPreference;
use App\Core\Notifications\Models\NotificationSetting;
use Illuminate\Support\Collection;

/**
 * NOT-04, NOT-05: which channels a user gets an event type on, and whether
 * its email waits for a digest.
 *
 * A channel is on when the tenant made it mandatory, else as the user
 * set it, else as the event type's defaults. A user cannot switch off a
 * mandatory channel. Email of an event whose email is mandatory is always
 * sent immediately: a digest would hold a message the tenant requires
 * (approvals) for up to a week.
 */
class Preferences
{
    /**
     * @return list<string> the channels the tenant made mandatory for $type
     *                      (none when the type does not allow it)
     */
    public function mandatoryChannels(EventType $type, ?Collection $settings = null): array
    {
        if (! $type->mandatoryAllowed) {
            return [];
        }

        $setting = $settings !== null
            ? $settings->get($type->key)
            : NotificationSetting::query()->where('event_type', $type->key)->first();

        return array_values(array_intersect($type->channels, $setting?->mandatory_channels ?? []));
    }

    /** @return Collection<string, NotificationSetting> the tenant's settings by event type */
    public function settings(): Collection
    {
        return NotificationSetting::query()->get()->keyBy('event_type');
    }

    /** @return Collection<string, NotificationPreference> $user's preferences by event type */
    public function of(User $user): Collection
    {
        return NotificationPreference::query()->where('user_id', $user->getKey())->get()->keyBy('event_type');
    }

    /**
     * The effective choice of $user for $type.
     *
     * @return array{channels: array<string, array{enabled: bool, mandatory: bool}>, digest: string, digest_allowed: bool}
     */
    public function resolve(User $user, EventType $type, NotificationPreference|false|null $preference = false, ?array $mandatory = null): array
    {
        if ($preference === false) {
            $preference = NotificationPreference::query()->where('user_id', $user->getKey())->where('event_type', $type->key)->first();
        }

        $mandatory ??= $this->mandatoryChannels($type);
        $chosen = $preference?->channels ?? [];
        $channels = [];

        foreach ($type->channels as $channel) {
            $isMandatory = in_array($channel, $mandatory, true);
            $enabled = $isMandatory || (bool) ($chosen[$channel] ?? in_array($channel, $type->defaultChannels, true));
            $channels[$channel] = ['enabled' => $enabled, 'mandatory' => $isMandatory];
        }

        $digestAllowed = in_array(Channels::EMAIL, $type->channels, true) && ! in_array(Channels::EMAIL, $mandatory, true);

        return [
            'channels' => $channels,
            'digest' => $digestAllowed ? ($preference?->digest ?? Channels::DIGEST_IMMEDIATE) : Channels::DIGEST_IMMEDIATE,
            'digest_allowed' => $digestAllowed,
        ];
    }

    /** @return list<string> the channels $user gets $type on */
    public function enabledChannels(User $user, EventType $type): array
    {
        return array_keys(array_filter($this->resolve($user, $type)['channels'], fn (array $c) => $c['enabled']));
    }
}
