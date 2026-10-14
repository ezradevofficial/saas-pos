<?php

namespace Modules\HR\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a company setup row (HR-38): the subclass's permission held
 * anywhere to try, and at the row's company (`company_id`) to succeed.
 */
abstract class SetupStoreRequest extends HrRequest
{
    abstract protected function permission(): string;

    /** @return array<string, mixed> the row's own fields */
    abstract protected function fields(): array;

    public function authorize(): bool
    {
        return $this->access()->anywhere($this->user(), $this->permission());
    }

    public function rules(): array
    {
        return ['company_id' => ['required', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')], ...$this->fields()];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty() && ! $this->access()->at($this->user(), $this->permission(), Scope::company((string) $this->input('company_id')))) {
                $this->forbid();
            }
        }];
    }
}
