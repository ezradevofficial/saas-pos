<?php

namespace App\Core\Automation\Http\Requests;

use App\Core\Automation\Http\Lists\AutomationRuleList;
use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Tenancy\Http\Requests\ListRequest;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * AUTO-01..AUTO-03: GET automation-rules, the rules the user may see
 * (RBAC-04): `?status=active` (default: not archived), `enabled`,
 * `disabled`, `archived` or `all`; `?type=` (a document type key);
 * `?company=` (that company's rules and those of every company);
 * `?search=` (name); `?sort`, pages of `?per_page` and an export
 * (AutomationRuleList).
 */
class ListRulesRequest extends AutomationViewRequest
{
    use SortsAndExports;

    public const STATUSES = ['active', 'enabled', 'disabled', 'archived', 'all'];

    public function list(): ListDefinition
    {
        return new AutomationRuleList;
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'type' => ['sometimes', 'string', Rule::in(app(DocumentTypeRegistry::class)->keys())],
            // Row-level security limits this to the tenant's companies.
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }

    public function applyStatus(Builder $query): Builder
    {
        return match ($this->validated('status', 'active')) {
            'enabled' => $query->whereNull('archived_at')->where('enabled', true),
            'disabled' => $query->whereNull('archived_at')->where('enabled', false),
            'archived' => $query->whereNotNull('archived_at'),
            'all' => $query,
            default => $query->whereNull('archived_at'),
        };
    }
}
