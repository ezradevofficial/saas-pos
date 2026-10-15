<?php

namespace Modules\Manufacturing\Http\Requests;

use App\Core\CustomFields\CustomFieldValidator;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Manufacturing\CustomFields\OrderEntity;
use Modules\Manufacturing\Support\Places;

/**
 * POST manufacturing/orders (MFG-10): `manufacturing.order.create` at the
 * location (403); `{location_id, item_id, bom_version_id?, qty, uom_id?,
 * planned_start?, planned_end?, note?, custom?}`.
 */
class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'manufacturing.order.create');
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid', Rule::exists('locations', 'id')],
            'item_id' => ['required', 'uuid'],
            'bom_version_id' => ['sometimes', 'nullable', 'uuid'],
            ...self::fields(required: true),
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function fields(bool $required): array
    {
        return [
            'qty' => [$required ? 'required' : 'sometimes', 'string', ManufacturingRules::QTY],
            'uom_id' => ['sometimes', 'nullable', 'uuid'],
            'planned_start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'planned_end' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:planned_start'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ...CustomFieldValidator::rules(),
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isEmpty() && ! app(Places::class)->at($this->user(), 'manufacturing.order.create', (string) $this->input('location_id'))) {
                    throw new AuthorizationException(__('rbac.forbidden'));
                }
            },
            fn (Validator $validator) => app(CustomFieldValidator::class)->validate($validator, OrderEntity::KEY, $this->input('custom'), null, $this->user()),
        ];
    }
}
