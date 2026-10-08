<?php

namespace App\Core\Workflow\Http\Requests;

use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\WorkflowAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * WF-10: a document's flow, addressed by type and document id (route
 * binding finds its running flow, else its latest). Users who may not see
 * the document's flow get 404 (RBAC-04); what they may do in it is checked
 * by the engine per stage (WF-08).
 */
class DocumentWorkflowRequest extends FormRequest
{
    private ?DocumentScope $scope = null;

    public function authorize(): bool
    {
        $scope = $this->documentScope();

        abort_if($scope === null || ! app(WorkflowAccess::class)->seesDocument($this->user(), $this->documentType(), $scope), 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function workflow(): DocumentWorkflow
    {
        return $this->route('document');
    }

    public function documentType(): DocumentType
    {
        return app(DocumentTypeRegistry::class)->get($this->workflow()->document_type);
    }

    public function documentScope(): ?DocumentScope
    {
        return $this->scope ??= $this->documentType()->scope($this->workflow()->document_id);
    }
}
