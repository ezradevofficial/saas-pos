<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Procurement\Models\LandedCost;

/**
 * POST procurement/landed-costs (PRC-17): `procurement.landed_cost.create`
 * at the branch; `{branch_id, cost_date, method: value|quantity|weight,
 * description?, charges: [{description, party_id?, currency, amount_minor}],
 * receipt_ids: [GRN id]}`. PATCH procurement/landed-costs/{id}
 * (UpdateLandedCostRequest) takes the same fields but the branch, each
 * optional, on a draft.
 */
class StoreLandedCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'procurement.landed_cost.create');
    }

    public function rules(): array
    {
        return [
            ...self::fields(required: true),
            'branch_id' => ProcurementRules::id('branches', required: true),
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function fields(bool $required): array
    {
        $need = $required ? 'required' : 'sometimes';

        return [
            'cost_date' => [$need, 'date_format:Y-m-d'],
            'method' => [$need, 'string', Rule::in(LandedCost::METHODS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
            'charges' => [$need, 'array', 'min:1', 'max:50'],
            'charges.*.description' => ['required', 'string', 'max:200'],
            'charges.*.party_id' => ProcurementRules::id('parties'),
            'charges.*.currency' => ['required', 'string', 'size:3'],
            'charges.*.amount_minor' => ['required', 'string', ProcurementRules::MINOR, 'not_in:0'],
            'receipt_ids' => [$need, 'array', 'min:1', 'max:50'],
            'receipt_ids.*' => ['uuid', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                ProcurementRules::atBranch($this->user(), 'procurement.landed_cost.create', (string) $this->input('branch_id'));
            }
        }];
    }
}
