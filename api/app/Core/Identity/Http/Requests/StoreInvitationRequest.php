<?php

namespace App\Core\Identity\Http\Requests;

use App\Core\Identity\Rules\LoginAvailable;
use App\Core\Identity\Support\PhoneNumber;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST invitations (AUTH-05): one contact (email or phone, not registered in
 * any tenant) and 1-20 assignments. Local phone numbers are read in the
 * given `country`, else the country of the tenant's first company. Whether
 * the inviter may grant each assignment is checked by Grants.
 */
class StoreInvitationRequest extends FormRequest
{
    public const MAX_ASSIGNMENTS = 20;

    public function authorize(): bool
    {
        return $this->user()->can('core.user.invite');
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $phone = $this->input('phone');
        $country = $this->input('country');
        $country = is_string($country) ? strtoupper($country) : Company::orderBy('created_at')->orderBy('id')->value('country');

        $this->merge([
            'email' => is_string($email) && trim($email) !== '' ? mb_strtolower(trim($email)) : null,
            'phone' => is_string($phone) && trim($phone) !== '' ? (PhoneNumber::normalise($phone, $country) ?? $phone) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'required_without:phone', 'prohibits:phone', 'string', 'email', 'max:255', new LoginAvailable],
            'phone' => ['nullable', 'required_without:email', 'string', 'regex:/^\+[1-9]\d{7,14}$/', new LoginAvailable],
            'country' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(PhoneNumber::COUNTRY_CODES))],
            'assignments' => ['required', 'array', 'min:1', 'max:'.self::MAX_ASSIGNMENTS],
            'assignments.*' => ['required', 'array:role_id,scope_type,scope_id'],
            'assignments.*.role_id' => ['required', 'uuid'],
            'assignments.*.scope_type' => ['required', 'string', Rule::in(Scope::TYPES)],
            'assignments.*.scope_id' => ['nullable', 'required_unless:assignments.*.scope_type,'.Scope::TENANT, 'uuid'],
        ];
    }
}
