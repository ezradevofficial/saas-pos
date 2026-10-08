<?php

namespace App\Core\Notifications\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;

/** NOT-03: read the notification templates (`core.notification_template.view`, tenant-wide). */
class ViewTemplatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('core.notification_template.view', Scope::tenant());
    }

    public function rules(): array
    {
        return [];
    }
}
