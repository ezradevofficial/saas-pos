<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Procurement\Models\Tender;

/**
 * POST procurement/tenders (PRC-06): `procurement.tender.create` at the
 * branch; `{branch_id, requisition_id?, title, description?, kind:
 * open|invited, currency?, deadline_at, opening_at, technical_weight
 * (0..100, the financial weight is the rest), pass_mark (0..100), lines?:
 * [{item_id?, description?, uom_id?, qty}], criteria?: [{name, weight,
 * max_score}], openers?: [user id], evaluators?: [user id]}`.
 */
class StoreTenderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'procurement.tender.create');
    }

    public function rules(): array
    {
        return [
            ...self::fields(),
            'branch_id' => ProcurementRules::id('branches', required: true),
            'requisition_id' => ProcurementRules::id('procurement_requisitions'),
            'title' => ['required', 'string', 'max:200'],
            'kind' => ['required', 'string', Rule::in([Tender::OPEN, Tender::INVITED])],
            'deadline_at' => ['required', 'date', 'after:now'],
            'opening_at' => ['required', 'date', 'after_or_equal:deadline_at'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function fields(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'kind' => ['sometimes', 'string', Rule::in([Tender::OPEN, Tender::INVITED])],
            'currency' => ['sometimes', 'string', 'size:3'],
            'deadline_at' => ['sometimes', 'date', 'after:now'],
            'opening_at' => ['sometimes', 'date'],
            'technical_weight' => ['sometimes', 'integer', 'between:0,100'],
            'pass_mark' => ['sometimes', 'numeric', 'between:0,100'],
            'lines' => ['sometimes', 'array', 'min:1', 'max:200'],
            ...ProcurementRules::lines('lines.*.', dimensions: false),
            'criteria' => ['sometimes', 'array', 'max:30'],
            'criteria.*.name' => ['required', 'string', 'max:200'],
            'criteria.*.weight' => ['required', 'integer', 'between:1,100'],
            'criteria.*.max_score' => ['sometimes', 'integer', 'between:1,1000'],
            'openers' => ['sometimes', 'array', 'max:20'],
            'openers.*' => ['uuid', 'distinct'],
            'evaluators' => ['sometimes', 'array', 'max:20'],
            'evaluators.*' => ['uuid', 'distinct'],
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

                ProcurementRules::atBranch($this->user(), 'procurement.tender.create', (string) $this->input('branch_id'));
            }
        }];
    }
}
