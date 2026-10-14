<?php

namespace Modules\Stores\Http\Requests;

use App\Core\Http\ApiException;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST stores/requisitions (STR-02): `stores.requisition.create` at the
 * store; `{location_id, department_id, cost_centre_id?, needed_by?, note?,
 * lines: [{item_id, uom_id?, qty, note?}]}`. Ids are checked under RLS
 * (another tenant's id does not exist: 422).
 */
class StoreRequisitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'stores.requisition.create');
    }

    public function rules(): array
    {
        return [
            'location_id' => ['required', 'uuid', Rule::exists('locations', 'id')->whereNull('archived_at')],
            'department_id' => ['required', 'uuid', Rule::exists('departments', 'id')->whereNull('archived_at')],
            'cost_centre_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('cost_centres', 'id')->whereNull('archived_at')],
            'needed_by' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'uuid', Rule::exists('items', 'id')->whereNull('archived_at')],
            'lines.*.uom_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('uoms', 'id')],
            'lines.*.qty' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,6})?$/'],
            'lines.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                self::at($this, 'stores.requisition.create', (string) $this->input('location_id'));
            }
        }];
    }

    /** 403 unless the request's user holds $permission at $locationId. */
    public static function at(FormRequest $request, string $permission, string $locationId): void
    {
        if (! app(ScopeResolver::class)->can($request->user(), $permission, Scope::location($locationId))) {
            throw new ApiException(403, 'forbidden', __('stores.errors.place_forbidden'));
        }
    }
}
