<?php

namespace App\Core\Tenancy\Http\Requests;

use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Visibility;
use Illuminate\Foundation\Http\FormRequest;

/** TEN-04: a branch is created at its company's scope. */
class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company $company */
        $company = $this->route('company');

        // RBAC-04: a company the user does not reach is not found.
        abort_unless(app(Visibility::class)->reaches($this->user(), 'core.branch.view', $company), 404);

        return $this->user()->can('create', [Branch::class, $company]);
    }

    protected function prepareForValidation(): void
    {
        BranchRules::normaliseCode($this);
    }

    public function rules(): array
    {
        return BranchRules::rules($this->route('company')->id, null);
    }
}
