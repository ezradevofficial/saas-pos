<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Validation\Rule;

/** GET fixed-assets/custodians (FA-04): `fixed_assets.asset.manage` at the company; `?company=&search=`: its users and, while HR is active, its employees. */
class CustodiansRequest extends CompanyRequest
{
    protected function permission(): string
    {
        return 'fixed_assets.asset.manage';
    }

    public function rules(): array
    {
        return [
            'company' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
