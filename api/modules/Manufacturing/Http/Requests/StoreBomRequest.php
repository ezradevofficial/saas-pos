<?php

namespace Modules\Manufacturing\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Manufacturing\CustomFields\BomEntity;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Support\Places;

/**
 * POST manufacturing/boms (MFG-02 to MFG-04): `manufacturing.bom.manage`
 * in the company (403); `{company_id, item_id, kind, name?, custom?,
 * version: {output_qty, output_uom_id?, labour_minutes?, note?, lines}}`.
 */
class StoreBomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'manufacturing.bom.manage');
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')],
            'item_id' => ['required', 'uuid'],
            'kind' => ['required', 'string', Rule::in(Bom::KINDS)],
            'name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'version' => ['required', 'array'],
            ...ManufacturingRules::version('version.', required: false),
            ...CustomFieldValidator::rules(),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isEmpty() && ! app(Places::class)->inCompany($this->user(), 'manufacturing.bom.manage', (string) $this->input('company_id'))) {
                    throw new AuthorizationException(__('rbac.forbidden'));
                }
            },
            fn (Validator $validator) => app(CustomFieldValidator::class)->validate($validator, BomEntity::KEY, $this->input('custom'), null, $this->user()),
        ];
    }
}
