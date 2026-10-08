<?php

namespace App\Core\Workflow\Http\Requests;

use App\Core\Workflow\WorkflowAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * WF-01: the document types and their fields, for the builder. Anyone
 * holding a `core.workflow.*` permission anywhere.
 */
class WorkflowViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(WorkflowAccess::class)->anywhere($this->user());
    }

    public function rules(): array
    {
        return [];
    }
}
