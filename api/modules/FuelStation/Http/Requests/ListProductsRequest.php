<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/products (FUEL-04): `fuel.setup.view` in the reader's companies; `?company_id=`, `?archived=1`. */
class ListProductsRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.setup.view';
    }

    public function rules(): array
    {
        return [
            'company_id' => ['sometimes', 'uuid'],
            'archived' => ['sometimes', 'boolean'],
            ...FuelRules::paging(),
        ];
    }
}
