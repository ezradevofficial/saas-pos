<?php

namespace App\Core\Notifications\Http\Requests;

use App\Core\Notifications\Channels;
use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * NOT-03: PUT notification-templates: the tenant's own text for one event
 * type, channel (`all` for every channel without its own) and language
 * (`core.notification_template.edit`, tenant-wide). Placeholders the
 * event does not have are refused. A blank subject keeps the default one.
 * The template is named in the body, not the URL: event type keys are
 * global, and the tenant API never changes anything through a global key.
 */
class UpdateTemplateRequest extends FormRequest
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
            'locale' => ['required', 'string', Rule::in(Channels::LOCALES)],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255', $this->placeholdersRule($type)],
            'body' => ['required', 'string', 'max:5000', $this->placeholdersRule($type)],
        ];
    }

    public function attributes(): array
    {
        return $this->notificationAttributes();
    }
}
