<?php

namespace App\Core\Approvals\Http\Requests;

use App\Core\Approvals\ApprovalAccess;
use App\Core\Approvals\Http\Lists\ApprovalList;
use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Http\Requests\ListRequest;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * APR-04: GET approvals, the signed-in user's approvals across modules and
 * companies:
 *
 * - `?status=` waiting (default: pending, the user or someone who
 *   delegated to them must act now), decided (the user decided them), all;
 * - `?view=all`: oversight of every request at places where the user holds
 *   `core.approval.view_all` (403 without it anywhere); `?status` then
 *   filters waiting (pending) and decided (anything else);
 * - `?type=` a document type key, `?company=` a company of the tenant,
 *   `?overdue=1` past the step's time limit, `?search=` in the document
 *   type, number, title and step;
 * - `?sort=due|-due|received|-received` (soonest due first by default),
 *   pages of `?per_page`, and an export (ApprovalList, EXP-01).
 *
 * No permission is needed for the user's own inbox.
 */
class ListApprovalsRequest extends FormRequest
{
    use SortsAndExports;

    public const STATUSES = ['waiting', 'decided', 'all'];

    public function list(): ListDefinition
    {
        return new ApprovalList;
    }

    public function authorize(): bool
    {
        if ($this->query('view') === 'all') {
            return app(ScopeResolver::class)->can($this->user(), ApprovalAccess::VIEW_ALL);
        }

        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'view' => ['sometimes', 'string', Rule::in(['mine', 'all'])],
            'type' => ['sometimes', 'string', Rule::in(app(DocumentTypeRegistry::class)->keys())],
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'overdue' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
