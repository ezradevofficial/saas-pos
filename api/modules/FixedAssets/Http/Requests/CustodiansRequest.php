<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\FixedAssets\Support\Access;

/**
 * GET fixed-assets/custodians (FA-04): `fixed_assets.asset.manage`
 * anywhere in the company (at it, or at one of its branches or locations,
 * review #7), since whoever registers assets picks their custodians;
 * `?company=&search=`: its users and, while HR is active, its active
 * employees.
 */
class CustodiansRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'fixed_assets.asset.manage';
    }

    public function authorize(): bool
    {
        $id = $this->companyId();

        return $id !== null && app(Access::class)->inCompany($this->user(), $this->permission(), $id);
    }

    public function rules(): array
    {
        return [
            'company' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
