<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** GET salon/clients/{party} and its visits: `salon.client.view` in the company the client belongs to (asked with company_id). */
class ClientRequest extends SalonRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    protected function target(): ?Scope
    {
        return $this->reachesCompany($this->input('company_id'));
    }
}
