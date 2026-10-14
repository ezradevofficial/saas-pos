<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Procurement\CustomFields\RequisitionEntity;
use Modules\Procurement\Models\Requisition;

/**
 * POST procurement/requisitions (PRC-02): `procurement.requisition.create`
 * at the branch; `{branch_id, location_id?, department_id?, purpose?,
 * needed_by?, currency?, lines: [{item_id?, description?, uom_id?, qty,
 * estimated_unit_price_minor?, cost_centre_id?, project_id?, needed_by?}],
 * custom?}`. PATCH procurement/requisitions/{id} (UpdateRequisitionRequest)
 * takes the same fields, each optional, on a draft.
 */
class StoreRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'procurement.requisition.create');
    }

    public function rules(): array
    {
        return [
            'branch_id' => ProcurementRules::id('branches', required: true),
            ...self::fields(required: true),
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function fields(bool $required): array
    {
        return [
            'location_id' => ProcurementRules::id('locations'),
            'department_id' => ProcurementRules::id('departments'),
            'purpose' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'needed_by' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'lines' => [$required ? 'required' : 'sometimes', 'array', 'min:1', 'max:200'],
            ...ProcurementRules::lines('lines.*.'),
            'lines.*.estimated_unit_price_minor' => ['sometimes', 'nullable', 'string', ProcurementRules::MINOR],
            'lines.*.needed_by' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            ...CustomFieldValidator::rules(),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isEmpty()) {
                    ProcurementRules::atBranch($this->user(), 'procurement.requisition.create', (string) $this->input('branch_id'));
                }
            },
            fn (Validator $validator) => app(CustomFieldValidator::class)->validate($validator, RequisitionEntity::KEY, $this->input('custom'), null, $this->user()),
        ];
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return Requisition::STATUSES;
    }
}
