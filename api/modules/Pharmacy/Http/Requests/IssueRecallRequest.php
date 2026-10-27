<?php

namespace Modules\Pharmacy\Http\Requests;

use Illuminate\Validation\Validator;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Support\Access;

/** POST pharmacy/recalls (PHA-22): `pharmacy.recall.issue`; `{item_id, batch_number, reason, reference?}`. A recall covers the whole tenant. */
class IssueRecallRequest extends PharmacyRequest
{
    protected function permission(): string
    {
        return 'pharmacy.recall.issue';
    }

    /** A recall blocks the batch in every company that has it, so the right must reach all of them (RBAC-04). */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $companies = Lot::query()->where('item_id', $this->input('item_id'))->whereRaw('lower(batch_number) = lower(?)', [trim((string) $this->input('batch_number'))])
                ->distinct()->pluck('company_id')->all();
            app(Access::class)->requireCompanies($this->user(), $this->permission(), $companies);
        }];
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
