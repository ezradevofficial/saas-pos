<?php

namespace Modules\FuelStation\Http\Requests;

use Illuminate\Validation\Rule;

/** GET fuel/vouchers (FUEL-21): `fuel.voucher.view` in the reader's companies; `?company_id=`, `?status=`, `?search=` (code). */
class ListVouchersRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.voucher.view';
    }

    public function rules(): array
    {
        return [
            'company_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'void'])],
            'search' => ['sometimes', 'string', 'max:40'],
            ...FuelRules::paging(),
        ];
    }
}
