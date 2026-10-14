<?php

namespace Modules\Loyalty\Http\Requests;

/** GET companies/{company}/loyalty-programme (LOY-01): `loyalty.programme.view` (or edit) at the company. */
class ShowProgrammeRequest extends LoyaltyRequest
{
    public function authorize(): bool
    {
        $this->authorizeAt($this->route('company')->id, ['loyalty.programme.view', 'loyalty.programme.edit']);

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
