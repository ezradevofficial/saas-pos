<?php

namespace Modules\Pharmacy\Http\Requests;

/** POST pharmacy/recalls (PHA-22): `pharmacy.recall.issue`; `{item_id, batch_number, reason, reference?}`. A recall covers the whole tenant. */
class IssueRecallRequest extends PharmacyRequest
{
    protected function permission(): string
    {
        return 'pharmacy.recall.issue';
    }

    public function rules(): array
    {
        return [
            'item_id' => PharmacyRules::item(),
            'batch_number' => ['required', 'string', 'max:60'],
            'reason' => ['required', 'string', 'max:1000'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
