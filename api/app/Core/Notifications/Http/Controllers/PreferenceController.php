<?php

namespace App\Core\Notifications\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Notifications\Channels;
use App\Core\Notifications\EventType;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Http\Requests\UpdatePreferencesRequest;
use App\Core\Notifications\Models\NotificationPreference;
use App\Core\Notifications\Preferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * NOT-04, NOT-05: the signed-in user's own notification preferences, per
 * event type of the core and the tenant's active modules: each channel on
 * or off (mandatory ones locked on) and email immediately or in a digest.
 */
class PreferenceController
{
    public function __construct(
        private readonly EventTypes $types,
        private readonly Preferences $preferences,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->present($request->user())]);
    }

    public function update(UpdatePreferencesRequest $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($request, $user) {
            foreach ($request->validated('preferences') as $entry) {
                $type = $this->types->get($entry['event_type']);
                $preference = NotificationPreference::query()->firstOrNew(['user_id' => $user->id, 'event_type' => $type->key]);
                $channels = $preference->channels ?? [];

                foreach ($entry['channels'] ?? [] as $channel => $on) {
                    $channels[$channel] = filter_var($on, FILTER_VALIDATE_BOOL);
                }

                $preference->channels = $channels;
                $preference->digest = $entry['digest'] ?? $preference->digest ?? Channels::DIGEST_IMMEDIATE;
                $preference->save();
            }
        });

        return new JsonResponse(['data' => $this->present($user)]);
    }

    /** @return list<array<string, mixed>> */
    private function present(User $user): array
    {
        $settings = $this->preferences->settings();
        $mine = $this->preferences->of($user);

        return array_values(array_map(function (EventType $type) use ($user, $settings, $mine) {
            $choice = $this->preferences->resolve($user, $type, $mine->get($type->key), $this->preferences->mandatoryChannels($type, $settings));

            return [
                'event_type' => $type->key,
                'label' => $type->label(),
                'module' => $type->module,
                'channels' => array_map(fn (string $channel, array $state) => [
                    'channel' => $channel,
                    'label' => __("notifications.channels.{$channel}"),
                    ...$state,
                ], array_keys($choice['channels']), $choice['channels']),
                'digest' => $choice['digest'],
                'digest_allowed' => $choice['digest_allowed'],
            ];
        }, array_filter($this->types->active(), fn (EventType $type) => ! $type->contactsOnly)));
    }
}
