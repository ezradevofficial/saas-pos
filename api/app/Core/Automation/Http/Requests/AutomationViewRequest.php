<?php

namespace App\Core\Automation\Http\Requests;

use App\Core\Automation\AutomationAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The trigger and action catalogue and the template library (AUTO-01,
 * AUTO-03, AUTO-07): anyone holding a `core.automation.*` permission
 * anywhere in the tenant.
 */
class AutomationViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(AutomationAccess::class)->anywhere($this->user());
    }

    public function rules(): array
    {
        return [];
    }
}
