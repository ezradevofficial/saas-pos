<?php

namespace Modules\Salon\Http\Requests;

/** POST salon/packages/{id}/adjust (SAL-18): `salon.package.adjust` at the package's company, with a reason. */
class AdjustPackageRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'sessions_used' => ['required', 'integer', 'min:0', 'max:200'],
            'reason' => ['required', 'string', 'max:200'],
        ];
    }
}
