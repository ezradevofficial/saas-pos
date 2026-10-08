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
        abort_unless($run->rule !== null && app(AutomationAccess::class)->sees($this->user(), $run->rule), 404);

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
