<?php

namespace App\Core\Automation\Http\Requests;

use App\Core\Automation\AutomationAccess;
use App\Core\Automation\Http\Lists\AutomationRunList;
use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Closure;
use Illuminate\Validation\Rule;

/**
 * AUTO-05: GET automation-runs, the run log of the rules the user may see:
 * `?rule=` (a rule id), `?outcome=` (succeeded, skipped, failed,
 * throttled, loop_blocked, queued, running, retrying), `?sort`, pages of
 * `?per_page` and an export (AutomationRunList). Newest first.
 */
class ListRunsRequest extends AutomationViewRequest
{
    use SortsAndExports;

    public function list(): ListDefinition
    {
        return new AutomationRunList;
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
            'outcome' => ['sometimes', 'string', Rule::in(AutomationRun::OUTCOMES)],
            'rule' => ['sometimes', 'uuid', function (string $attribute, mixed $value, Closure $fail) {
                $rule = AutomationRule::query()->find($value);

                if ($rule === null || ! app(AutomationAccess::class)->sees($this->user(), $rule)) {
                    $fail(__('validation.exists', ['attribute' => __('automation.attributes.rule')]));
                }
            }],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
