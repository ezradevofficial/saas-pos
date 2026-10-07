<?php

namespace App\Core\Tenancy\Http\Requests;

use App\Core\Tenancy\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Branch $branch */
        $branch = $this->route('branch');

        abort_unless($this->user()->can('view', $branch), 404);

        return $this->user()->can('update', $branch);
    }

    protected function prepareForValidation(): void
    {
        BranchRules::normaliseCode($this);
    }

    public function rules(): array
    {
        $branch = $this->route('branch');

        return BranchRules::rules($branch->company_id, $branch, updating: true);
    }
}
