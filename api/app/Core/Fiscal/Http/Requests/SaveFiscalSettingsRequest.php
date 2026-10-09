<?php

namespace App\Core\Fiscal\Http\Requests;

use App\Core\Fiscal\FiscalDrivers;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PUT companies/{company}/fiscal-settings (`core.fiscal.edit`): the
 * driver (one of the company's country's, `fake` only in local and
 * testing), `enabled`, the taxpayer PIN (`tin`), `branch_code` and
 * `device_serial` the authority registered, plain `settings` (default
 * item classification and unit codes) and `credentials` the driver takes
 * (stored encrypted, never returned; a null value clears a key).
 * The driver, `enabled`, credentials and the identity the authority
 * registered (`tin`, `branch_code`, `device_serial`) also need
 * `core.fiscal.configure` at the company (Owner, Admin): an Accountant
 * edits the default codes only.
 */
class SaveFiscalSettingsRequest extends CompanyFiscalRequest
{
    public const SETTINGS = ['default_item_class_code', 'default_packaging_unit_code', 'default_quantity_unit_code'];

    public const CREDENTIALS = ['cmc_key', 'api_token'];

    protected bool $edits = true;

    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        $configures = collect(['driver', 'enabled', 'credentials', 'tin', 'branch_code', 'device_serial'])->contains(fn (string $field) => $this->has($field));

        return ! $configures || $this->user()->can('core.fiscal.configure', $this->company());
    }

    public function rules(): array
    {
        return [
            'driver' => ['sometimes', 'string', Rule::in(app(FiscalDrivers::class)->choices((string) $this->company()->country))],
            'enabled' => ['sometimes', 'boolean'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-]+\z/'],
            'branch_code' => ['sometimes', 'nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+\z/'],
            'device_serial' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9\-_.]+\z/'],
            'settings' => ['sometimes', 'array'],
            'settings.*' => ['nullable', 'string', 'max:50'],
            'credentials' => ['sometimes', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (['settings' => self::SETTINGS, 'credentials' => self::CREDENTIALS] as $field => $allowed) {
                $unknown = array_diff(array_map('strval', array_keys((array) $this->input($field, []))), $allowed);

                if ($unknown !== []) {
                    $validator->errors()->add($field, __("fiscal.errors.{$field}_unknown", ['keys' => implode(', ', $allowed)]));
                }
            }
        });
    }

    public function messages(): array
    {
        return ['driver.in' => __('fiscal.errors.driver_invalid')];
    }
}
