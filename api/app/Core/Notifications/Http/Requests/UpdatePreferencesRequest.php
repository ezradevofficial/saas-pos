<?php

namespace App\Core\Notifications\Http\Requests;

use App\Core\Notifications\Channels;
use App\Core\Notifications\Preferences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * NOT-04, NOT-05: PUT me/notification-preferences: the signed-in user's
 * own channels (`{"email": false}`; channels left out keep their setting)
 * and email timing per event type. Every user may change their own; a
 * channel the tenant made mandatory cannot be switched off, and its email
 * cannot wait for a digest.
 */
class UpdatePreferencesRequest extends FormRequest
{
    use NotificationRules;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'preferences' => ['required', 'array', 'min:1', 'max:200'],
            'preferences.*' => ['required', 'array:event_type,channels,digest'],
            'preferences.*.event_type' => ['required', 'string', 'max:100', 'distinct', $this->eventTypeRule()],
            'preferences.*.channels' => ['sometimes', 'array'],
            'preferences.*.channels.*' => ['required', 'boolean'],
            'preferences.*.digest' => ['sometimes', 'string', Rule::in(Channels::DIGESTS)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $preferences = app(Preferences::class);
            $settings = $preferences->settings();

            foreach ($this->input('preferences', []) as $index => $entry) {
                $type = $this->eventTypeOf($entry['event_type'] ?? null);

                if ($type === null) {
                    continue;
                }

                $mandatory = $preferences->mandatoryChannels($type, $settings);

                foreach ($entry['channels'] ?? [] as $channel => $on) {
                    $field = "preferences.{$index}.channels.{$channel}";

                    if (! in_array($channel, $type->channels, true)) {
                        $validator->errors()->add($field, __('notifications.errors.channel_not_offered', [
                            'event' => $type->label(),
                            'channel' => in_array($channel, Channels::ALL, true) ? __("notifications.channels.{$channel}") : mb_substr((string) $channel, 0, 30),
                        ]));
                    } elseif (! filter_var($on, FILTER_VALIDATE_BOOL) && in_array($channel, $mandatory, true)) {
                        $validator->errors()->add($field, __('notifications.errors.mandatory_channel', [
                            'event' => $type->label(), 'channel' => __("notifications.channels.{$channel}"),
                        ]));
                    }
                }

                $digest = $entry['digest'] ?? Channels::DIGEST_IMMEDIATE;
                $digestAllowed = in_array(Channels::EMAIL, $type->channels, true) && ! in_array(Channels::EMAIL, $mandatory, true);

                if ($digest !== Channels::DIGEST_IMMEDIATE && ! $digestAllowed) {
                    $validator->errors()->add("preferences.{$index}.digest", __('notifications.errors.digest_not_allowed', ['event' => $type->label()]));
                }
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'preferences' => __('notifications.attributes.event_type'),
            'preferences.*.event_type' => __('notifications.attributes.event_type'),
            'preferences.*.channels' => __('notifications.attributes.channels'),
            'preferences.*.channels.*' => __('notifications.attributes.channel'),
            'preferences.*.digest' => __('notifications.attributes.digest'),
        ];
    }
}
