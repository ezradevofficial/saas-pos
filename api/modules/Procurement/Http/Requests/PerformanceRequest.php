<?php

namespace Modules\Procurement\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET procurement/supplier-performance (PRC-18): `procurement.performance.view`;
 * the documents of the places where the reader holds it (RBAC-04);
 * `?from=` and `?to=` (default: the last 90 days), `?company=`, `?party=`,
 * `?format=csv` (EXP-01).
 */
class PerformanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'procurement.performance.view');
    }

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'company' => ['sometimes', 'uuid', Rule::exists('companies', 'id')],
            'party' => ['sometimes', 'uuid', Rule::exists('parties', 'id')],
            'format' => ['sometimes', 'string', 'in:csv'],
        ];
    }
}
