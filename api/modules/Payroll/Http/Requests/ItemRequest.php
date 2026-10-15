<?php

namespace Modules\Payroll\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Payroll\Models\Item;

/**
 * PATCH payroll/items/{payroll_item}, POST …/archive (PAY-04): not found
 * unless the reader reads the company's payroll setup;
 * `payroll.settings.manage` at the company to change it. The kind never
 * changes; the flags of one kind are refused on the other.
 */
class ItemRequest extends PayrollRequest
{
    public static function rulesFor(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:40', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'name' => [$required, 'string', 'max:120'],
            'kind' => [$creating ? 'required' : 'prohibited', 'string', Rule::in([Item::EARNING, Item::DEDUCTION])],
            'taxable' => ['sometimes', 'boolean'],
            'contributory' => ['sometimes', 'boolean'],
            'pre_tax' => ['sometimes', 'boolean'],
            'insurance_premium' => ['sometimes', 'boolean'],
        ];
    }

    public function item(): Item
    {
        return $this->route('payroll_item');
    }

    public function authorize(): bool
    {
        return $this->onCompany($this->item()->company_id, StoreSetupRequest::MANAGE, SetupListRequest::VIEW);
    }

    public function rules(): array
    {
        return $this->isMethod('PATCH') ? self::rulesFor(false) : [];
    }
}
