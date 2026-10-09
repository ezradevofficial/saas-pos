<?php

namespace App\Core\Fiscal\Http\Requests;

/**
 * POST companies/{company}/fiscal-submissions/send-earlier
 * (`core.fiscal.edit`): queue the company's documents issued since `from`
 * (a date, not in the future). `confirm` must be true: the back office
 * asks first, as these documents reach the tax authority.
 */
class SendEarlierDocumentsRequest extends CompanyFiscalRequest
{
    protected bool $edits = true;

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'confirm' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return ['confirm.accepted' => __('fiscal.errors.confirm_required')];
    }
}
