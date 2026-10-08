<?php

namespace App\Core\Workflow\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Tenancy\Http\Requests\ListRequest;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Http\Lists\WorkflowDefinitionList;
use Illuminate\Validation\Rule;

/**
 * WF-02, spec 6.4: GET workflows, the flows the user may see (RBAC-04):
 * `?type=` (a document type key), `?company=` (a company id; the flows of
 * that company and the one for every company), `?search=` (type key),
 * `?sort`, pages of `?per_page` and an export (WorkflowDefinitionList).
 */
class ListWorkflowsRequest extends WorkflowViewRequest
{
    use SortsAndExports;

    public function list(): ListDefinition
    {
        return new WorkflowDefinitionList;
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', 'string', Rule::in(app(DocumentTypeRegistry::class)->keys())],
            // Row-level security limits this to the tenant's companies.
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
