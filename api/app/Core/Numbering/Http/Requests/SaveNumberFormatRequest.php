<?php

namespace App\Core\Numbering\Http\Requests;

use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\NumberFormat;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Visibility;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT numbering/formats: set how a document type is numbered for the
 * tenant (no company), a company, or a branch of it (NUM-01). Needs
 * `core.numbering.edit` at that scope; a company or branch the user does
 * not reach with `core.numbering.view` is not found (RBAC-04).
 */
class SaveNumberFormatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'core.numbering.view')
            || app(ScopeResolver::class)->can($this->user(), 'core.numbering.edit');
    }

    public function rules(): array
    {
        $types = array_map(fn (DocumentNumberType $type) => $type->key, app(DocumentNumberTypes::class)->active());

        return [
            'document_type' => ['required', 'string', Rule::in($types)],
            // Under RLS: another tenant's id does not exist.
            'company_id' => ['present', 'nullable', 'uuid', Rule::exists('companies', 'id'), 'required_with:branch_id'],
            'branch_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('branches', 'id'), function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== null && Branch::query()->whereKey($value)->value('company_id') !== $this->input('company_id')) {
                    $fail(__('core.numbering.errors.branch_company'));
                }
            }],
            'pattern' => ['required', 'string', 'max:60'],
            'reset' => ['required', 'string', Rule::in(NumberFormat::RESETS)],
            'gapless' => ['sometimes', 'boolean'],
        ];
    }

    /** The scope the format applies at, after checking the user reaches it (404) and may edit there (403). */
    public function authorizedScope(): Scope
    {
        $companyId = $this->validated('company_id');
        $branchId = $this->validated('branch_id');
        $node = $branchId !== null ? Branch::findOrFail($branchId) : ($companyId !== null ? Company::findOrFail($companyId) : null);

        if ($node !== null) {
            abort_unless(app(Visibility::class)->reaches($this->user(), 'core.numbering.view', $node)
                || app(Visibility::class)->reaches($this->user(), 'core.numbering.edit', $node), 404);
        }

        $scope = match (true) {
            $branchId !== null => Scope::branch($branchId),
            $companyId !== null => Scope::company($companyId),
            default => Scope::tenant(),
        };

        abort_unless(app(ScopeResolver::class)->can($this->user(), 'core.numbering.edit', $scope), 403);

        return $scope;
    }

    /** @return array{document_type: string, company_id: ?string, branch_id: ?string, pattern: string, reset: string, gapless: bool} */
    public function numberFormat(): array
    {
        return [
            'document_type' => $this->validated('document_type'),
            'company_id' => $this->validated('company_id'),
            'branch_id' => $this->validated('branch_id'),
            'pattern' => $this->validated('pattern'),
            'reset' => $this->validated('reset'),
            'gapless' => (bool) $this->validated('gapless', false),
        ];
    }
}
