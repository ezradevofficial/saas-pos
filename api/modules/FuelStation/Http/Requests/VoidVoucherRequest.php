<?php

namespace Modules\FuelStation\Http\Requests;

/** POST fuel/vouchers/{id}/void (FUEL-21): `fuel.voucher.manage` in the company. `{reason}`. */
class VoidVoucherRequest extends FuelRequest
{
    protected function permission(): string
    {
        return 'fuel.voucher.manage';
    }

    protected function routeCompanyRecord(): ?string
    {
        return 'fuel_voucher';
    }

    public function rules(): array
    {
        return [
            'reason' => FuelRules::reason(),
        ];
    }
}
