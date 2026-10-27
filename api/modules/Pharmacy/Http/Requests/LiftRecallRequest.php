<?php

namespace Modules\Pharmacy\Http\Requests;

/** POST pharmacy/recalls/{recall}/lift (PHA-22): `pharmacy.recall.lift`; `{reason}`. */
class LiftRecallRequest extends PharmacyRequest
{
    protected function permission(): string
    {
        return 'pharmacy.recall.lift';
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
