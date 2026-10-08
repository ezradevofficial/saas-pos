<?php

namespace App\Core\Notifications\Http\Requests;

use App\Core\Notifications\Channels;
use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** NOT-03: POST notification-templates/reset: back to the default text (`core.notification_template.edit`). */
class ResetTemplateRequest extends FormRequest
{
    use NotificationRules;

    public function authorize(): bool
    {
        return $this->user()->can('core.notification_template.edit', Scope::tenant());
    }

    public function rules(): array
    {
        $type = $this->eventTypeOf($this->input('event_type'));

        return [
            'event_type' => ['required', 'string', 'max:100', $this->eventTypeRule()],
            'channel' => ['required', 'string', Rule::in([Channels::ANY, ...Channels::ALL]), $this->channelRule($type, allowAll: true)],
            // One text for everyone, in the organisation's language (NOT-03).
            'locale' => ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return $this->notificationAttributes();
    }
}
