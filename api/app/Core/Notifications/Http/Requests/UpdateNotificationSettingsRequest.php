<?php

namespace App\Core\Notifications\Http\Requests;

use App\Core\Notifications\Channels;
use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * NOT-04: PUT notification-settings: the channels users must keep on, per
 * event type (`core.notification_settings.edit`, tenant-wide). Only types
 * that allow it can have mandatory channels; an empty list makes none
 * mandatory. Types left out keep their setting.
 */
class UpdateNotificationSettingsRequest extends FormRequest
{
    use NotificationRules;

    public function authorize(): bool
    {
        return $this->user()->can('core.notification_settings.edit', Scope::tenant());
    }

    public function rules(): array
    {
        return [
            'event_types' => ['required', 'array', 'min:1', 'max:200'],
            'event_types.*' => ['required', 'array:event_type,mandatory_channels'],
            'event_types.*.event_type' => ['required', 'string', 'max:100', 'distinct', $this->eventTypeRule()],
            'event_types.*.mandatory_channels' => ['present', 'array'],
            'event_types.*.mandatory_channels.*' => ['required', 'string', 'distinct', Rule::in(Channels::ALL)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->input('event_types', []) as $index => $entry) {
                $type = $this->eventTypeOf($entry['event_type'] ?? null);
                $channels = $entry['mandatory_channels'] ?? [];

                if ($type === null || $channels === []) {
                    continue;
                }

                if (! $type->mandatoryAllowed) {
                    $validator->errors()->add("event_types.{$index}.mandatory_channels", __('notifications.errors.mandatory_not_allowed', ['event' => $type->label()]));

                    continue;
                }

                foreach ($channels as $position => $channel) {
                    if (! in_array($channel, $type->channels, true)) {
                        $validator->errors()->add("event_types.{$index}.mandatory_channels.{$position}", __('notifications.errors.channel_not_offered', [
                            'event' => $type->label(), 'channel' => __("notifications.channels.{$channel}"),
                        ]));
                    }
                }
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'event_types' => __('notifications.attributes.event_type'),
            'event_types.*.event_type' => __('notifications.attributes.event_type'),
            'event_types.*.mandatory_channels' => __('notifications.attributes.mandatory_channels'),
            'event_types.*.mandatory_channels.*' => __('notifications.attributes.channel'),
        ];
    }
}
