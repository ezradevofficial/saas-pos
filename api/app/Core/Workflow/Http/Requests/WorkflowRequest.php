<?php

namespace App\Core\Workflow\Http\Requests;

use App\Core\Workflow\Models\WorkflowDefinition;
use App\Core\Workflow\WorkflowAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A request on one flow (WF-02, spec 6.4). A flow the user cannot see is
 * not found (RBAC-04); `$action` (edit or publish) is then checked at the
 * flow's company, or at tenant scope for the flow of every company.
 */
class WorkflowRequest extends FormRequest
{
    /** null: reading is enough. */
    protected ?string $action = null;

    public function authorize(): bool
    {
        $access = app(WorkflowAccess::class);
        $definition = $this->definition();

        abort_unless($access->sees($this->user(), $definition), 404);

        return $this->action === null || $access->may($this->user(), $this->action, $definition->company_id);
    }

    public function rules(): array
    {
        return [];
    }

    public function definition(): WorkflowDefinition
    {
        return $this->route('workflow');
    }
}
