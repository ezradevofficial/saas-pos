<?php

namespace Modules\FixedAssets\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\FixedAssets\CustomFields\AssetEntity;
use Modules\FixedAssets\Depreciation\AssetBook;
use Modules\FixedAssets\Support\Access;

/**
 * POST fixed-assets/assets (FA-04): `fixed_assets.asset.manage` at the
 * location; `{location_id, name, number?, category_id?, description?,
 * custodian_user_id?, custodian_employee_id?, cost_centre_id?,
 * department_id?, serial_number?, acquired_on?, in_service_on?, currency?,
 * cost_minor?, supplier_id?, supplier_reference?, warranty_until?,
 * warranty_note?, method?, useful_life_months?, residual_minor?,
 * declining_rate?, total_units?, meter_start?, custom?}`. PATCH
 * fixed-assets/assets/{id} (UpdateAssetRequest) takes the same fields,
 * each optional.
 */
class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'fixed_assets.asset.manage');
    }

    public function rules(): array
    {
        return [...self::fields(required: true), 'location_id' => ['required', 'uuid', Rule::exists('locations', 'id')]];
    }

    /** @return array<string, list<mixed>> */
    public static function fields(bool $required): array
    {
        return [
            'name' => [$required ? 'required' : 'sometimes', 'string', 'max:200'],
            'number' => ['sometimes', 'nullable', 'string', 'max:80'],
            'category_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('fa_categories', 'id')],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'custodian_user_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('users', 'id')],
            'custodian_employee_id' => ['sometimes', 'nullable', 'uuid'],
            'cost_centre_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('cost_centres', 'id')],
            'department_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('departments', 'id')],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'acquired_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'in_service_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'cost_minor' => ['sometimes', 'integer', 'min:0', 'max:9000000000000000'],
            'supplier_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('parties', 'id')],
            'supplier_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'warranty_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'warranty_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'method' => ['sometimes', 'nullable', 'string', Rule::in(AssetBook::METHODS)],
            'useful_life_months' => ['sometimes', 'nullable', 'integer', 'between:1,1200'],
            'residual_minor' => ['sometimes', 'integer', 'min:0', 'max:9000000000000000'],
            'declining_rate' => ['sometimes', 'nullable', 'decimal:0,4', 'gt:0', 'max:100'],
            'total_units' => ['sometimes', 'nullable', 'decimal:0,6', 'gt:0', 'max:99999999999999'],
            'meter_start' => ['sometimes', 'decimal:0,6', 'min:0', 'max:99999999999999'],
            ...CustomFieldValidator::rules(),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isEmpty() && ! app(Access::class)->at($this->user(), 'fixed_assets.asset.manage', (string) $this->input('location_id'))) {
                    $validator->errors()->add('location_id', __('fixed_assets.errors.location_forbidden'));
                }
            },
            fn (Validator $validator) => app(CustomFieldValidator::class)->validate($validator, AssetEntity::KEY, $this->input('custom'), null, $this->user()),
        ];
    }
}
