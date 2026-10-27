<?php

namespace Modules\FuelStation\Http\Requests;

/** GET fuel/vouchers/{id} (FUEL-21): `fuel.voucher.view` in the company. */
class ShowVoucherRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.voucher.view';
    }

    protected function routeCompanyRecord(): ?string
    {
        return 'fuel_voucher';
    }

    public function rules(): array
    {
        return [];
    }
}
