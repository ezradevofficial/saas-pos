<?php

namespace App\Core\Notifications\Http\Requests;

use App\Core\Notifications\Channels;
use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * NOT-03: POST notification-templates/preview: a text (the one being
 * edited, else the one in use) rendered with the event's sample values.
 * Changes nothing; `core.notification_template.view`.
 */
class PreviewTemplateRequest extends FormRequest
{
    use NotificationRules;

    public function authorize(): bool
    {
        return $this->user()->can('core.notification_template.view', Scope::tenant());
    }

    public function rules(): array
    {
        $type = $this->eventTypeOf($this->input('event_type'));

        return [
            'event_type' => ['required', 'string', 'max:100', $this->eventTypeRule()],
            'channel' => ['required', 'string', Rule::in([Channels::ANY, ...Channels::ALL]), $this->channelRule($type, allowAll: true)],
            'locale' => ['required', 'string', Rule::in(Channels::LOCALES)],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255', $this->placeholdersRule($type)],
            'body' => ['sometimes', 'nullable', 'string', 'max:5000', $this->placeholdersRule($type)],
        ];
    }

    public function attributes(): array
    {
        return $this->notificationAttributes();
    }
}
