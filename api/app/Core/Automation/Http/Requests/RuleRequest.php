<?php

namespace App\Core\Automation\Http\Requests;

use App\Core\Automation\AutomationAccess;
use App\Core\Automation\Models\AutomationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request on one rule (AUTO-01..AUTO-05). A rule the user cannot see is
 * not found (RBAC-04); `$edit` requests then need `core.automation.edit`
 * at the rule's company (tenant scope for a rule of every company).
 * Enable, disable and archive use this class with edit on.
 */
class RuleRequest extends FormRequest
{
    protected bool $edit = false;

    public function authorize(): bool
    {
        $access = app(AutomationAccess::class);
        abort_unless($access->sees($this->user(), $this->rule()), 404);

        return ! $this->edit || $access->mayEdit($this->user(), $this->rule()->company_id);
    }

    public function rules(): array
    {
        return [];
    }

    public function rule(): AutomationRule
    {
        return $this->route('automation_rule');
    }
}
