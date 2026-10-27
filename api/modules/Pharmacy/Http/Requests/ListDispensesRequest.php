<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Pharmacy\Http\Lists\DispenseList;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Models\ItemProfile;

/**
 * GET pharmacy/dispenses and pharmacy/reports/prescriptions (PHA-24, PHA-26):
 * `pharmacy.report.view`; dispensed sale lines that needed a prescription or a
 * pharmacist, or are controlled, at the reader's locations; `?review=`
 * (review: none, open, reviewed), `?class=`, `?item_id=`, `?with_prescription=1`
 * (the prescription log), `?from=`/`?to=` (dispensed on), `?search=` (receipt
 * number).
 */
class ListDispensesRequest extends FormRequest
{
    use ListsPharmacy;

    public function list(): ListDefinition
    {
        return new DispenseList;
    }

    protected function viewPermission(): string
    {
        return 'pharmacy.report.view';
    }

    protected function extraRules(): array
    {
        return [
            'class' => ['sometimes', 'string', Rule::in(ItemProfile::CLASSES)],
            'item_id' => ['sometimes', 'uuid', Rule::exists('items', 'id')],
            'with_prescription' => ['sometimes', 'boolean'],
            'review' => ['sometimes', 'string', Rule::in([Dispense::REVIEW_NONE, Dispense::REVIEW_OPEN, Dispense::REVIEWED, 'all'])],
        ];
    }
}
