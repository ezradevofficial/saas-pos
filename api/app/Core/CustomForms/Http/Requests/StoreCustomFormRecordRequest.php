<?php

namespace App\Core\CustomForms\Http\Requests;

use App\Core\CustomForms\CustomFormAccess;
use App\Core\CustomForms\CustomFormType;
use App\Core\Http\ApiException;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * CF-04: POST custom-form-types/{type}/records: a new record at a company
 * (and a branch of it, and a location of that branch), for holders of
 * `core.custom_form.create` there (and of one of the type's roles, when it
 * names some; RBAC-04). Places of another tenant don't exist (row-level
 * security); a place the user can't create at is refused (403).
 */
class StoreCustomFormRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = $this->type();

        return ! $type->isArchived() && app(CustomFormAccess::class)->anywhere($this->user(), $type, [CustomFormAccess::CREATE]);
    }

    public function type(): CustomFormType
    {
        return $this->route('custom_form_type');
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')],
            'branch_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('branches', 'id')->whereNull('archived_at'), function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== null && Branch::query()->whereKey($value)->value('company_id') !== $this->input('company_id')) {
                    $fail(__('core.custom_form.errors.branch_company'));
                }
            }],
            'location_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('locations', 'id')->whereNull('archived_at'), function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== null && Location::query()->whereKey($value)->value('branch_id') !== $this->input('branch_id')) {
                    $fail(__('core.custom_form.errors.location_branch'));
                }
            }],
            ...CustomFormRecordRules::rules($this->type()),
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! app(CustomFormAccess::class)->at($this->user(), $this->type(), CustomFormAccess::CREATE, $this->place())) {
                throw new ApiException(403, 'forbidden', __('core.custom_form.errors.place_forbidden'));
            }

            CustomFormRecordRules::validate($validator, $this->type(), null, $this->user());
        }];
    }

    public function place(): Scope
    {
        return match (true) {
            $this->filled('location_id') => Scope::location((string) $this->input('location_id')),
            $this->filled('branch_id') => Scope::branch((string) $this->input('branch_id')),
            default => Scope::company((string) $this->input('company_id')),
        };
    }

    /** @return array{company_id: string, branch_id: ?string, location_id: ?string, custom: ?array, lines: ?array, attachments: ?array, submit: bool} */
    public function recordData(): array
    {
        return [
            'company_id' => $this->validated('company_id'),
            'branch_id' => $this->validated('branch_id'),
            'location_id' => $this->validated('location_id'),
            'custom' => $this->validated('custom'),
            'lines' => $this->validated('lines'),
            'attachments' => $this->validated('attachments'),
            'submit' => $this->boolean('submit'),
        ];
    }
}
