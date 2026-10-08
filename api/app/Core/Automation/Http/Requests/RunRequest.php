<?php

namespace App\Core\Automation\Http\Requests;

use App\Core\Automation\AutomationAccess;
use App\Core\Automation\Models\AutomationRun;
use Illuminate\Foundation\Http\FormRequest;

/** AUTO-05: GET automation-runs/{run}: a run of a rule the user may see, else not found. */
class RunRequest extends FormRequest
{
    public function authorize(): bool
    {
        $run = $this->run();
        $access = app(AutomationAccess::class);
        $companies = $access->companyIds($this->user());

        // A rule the reader sees, for a document in one of their companies.
        abort_unless($run->rule !== null && $access->sees($this->user(), $run->rule)
            && ($companies === null || $run->company_id === null || in_array($run->company_id, $companies, true)), 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function run(): AutomationRun
    {
        return $this->route('automation_run');
    }
}
