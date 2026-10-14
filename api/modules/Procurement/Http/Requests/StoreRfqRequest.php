<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST procurement/rfqs (PRC-03): `procurement.rfq.create` at the branch;
 * `{branch_id, requisition_id?, title, note?, deadline_at?, lines?: [{item_id?,
 * description?, uom_id?, qty, requisition_line_id?}], supplier_ids?: [party
 * id]}`; without lines, an approved requisition's lines are taken.
 */
class StoreRfqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'procurement.rfq.create');
    }

    public function rules(): array
    {
        return [
            ...self::fields(),
            'branch_id' => ProcurementRules::id('branches', required: true),
            'requisition_id' => ProcurementRules::id('procurement_requisitions'),
            'title' => ['required', 'string', 'max:200'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function fields(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:200'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'deadline_at' => ['sometimes', 'nullable', 'date'],
            'lines' => ['sometimes', 'array', 'min:1', 'max:200'],
            ...ProcurementRules::lines('lines.*.', dimensions: false, requisitionLine: true),
            'supplier_ids' => ['sometimes', 'array', 'max:50'],
            'supplier_ids.*' => ['uuid', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                if (! $this->filled('lines') && ! $this->filled('requisition_id')) {
                    $validator->errors()->add('lines', __('procurement.errors.document_empty'));

                    return;
                }

                ProcurementRules::atBranch($this->user(), 'procurement.rfq.create', (string) $this->input('branch_id'));
            }
        }];
    }
}
