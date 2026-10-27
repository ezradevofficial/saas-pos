<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** POST salon/clients (SAL-25): a new customer party with a salon profile; `salon.client.manage` at the company. */
class CreateClientRequest extends SalonRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
        ];
    }

    protected function target(): ?Scope
    {
        return $this->reachesCompany($this->input('company_id'));
    }
}
