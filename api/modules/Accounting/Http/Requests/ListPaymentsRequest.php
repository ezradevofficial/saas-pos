<?php

namespace Modules\Accounting\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Validation\Rule;
use Modules\Accounting\Http\Lists\PaymentList;
use Modules\Accounting\Models\Payment;

/** GET companies/{company}/accounting/payments (ACC-10): `?kind=receipt|payment`, `?party=`, dates, search, export. */
class ListPaymentsRequest extends ListRequest
{
    public function list(): ListDefinition
    {
        return new PaymentList;
    }

    protected function statuses(): array
    {
        return ['posted'];
    }

    protected function permission(): string
    {
        return 'accounting.payment.view';
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'kind' => ['sometimes', 'string', Rule::in(Payment::KINDS)],
            'party' => ['sometimes', 'uuid', Rule::exists('parties', 'id')],
        ];
    }
}
