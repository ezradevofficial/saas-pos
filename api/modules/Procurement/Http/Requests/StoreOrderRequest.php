<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Procurement\CustomFields\OrderEntity;

/**
 * POST procurement/orders (PRC-13): `procurement.po.create` at the branch;
 * `{branch_id, party_id, location_id?, currency?, tax_inclusive?,
 * order_date?, expected_on?, terms?, requisition_id?, lines: [{item_id?,
 * description?, uom_id?, qty, unit_price_minor, tax_code_id?,
 * cost_centre_id?, project_id?, requisition_line_id?}], custom?}`. PATCH
 * procurement/orders/{id} (UpdateOrderRequest) takes the same fields but
 * the branch and requisition, each optional, on a draft.
 */
class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'procurement.po.create');
    }

    public function rules(): array
    {
        return [
            ...self::fields(required: true),
            'branch_id' => ProcurementRules::id('branches', required: true),
            'requisition_id' => ProcurementRules::id('procurement_requisitions'),
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function fields(bool $required): array
    {
        return [
            'party_id' => ProcurementRules::id('parties', required: $required),
            'location_id' => ProcurementRules::id('locations'),
            'currency' => ['sometimes', 'string', 'size:3'],
            'tax_inclusive' => ['sometimes', 'boolean'],
            'order_date' => ['sometimes', 'date_format:Y-m-d'],
            'expected_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'lines' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:200'],
            ...ProcurementRules::lines('lines.*.', price: true, priceRequired: true, tax: true, requisitionLine: true),
            ...CustomFieldValidator::rules(),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isEmpty()) {
                    ProcurementRules::atBranch($this->user(), 'procurement.po.create', (string) $this->input('branch_id'));
                }
            },
            fn (Validator $validator) => app(CustomFieldValidator::class)->validate($validator, OrderEntity::KEY, $this->input('custom'), null, $this->user()),
        ];
    }
}
