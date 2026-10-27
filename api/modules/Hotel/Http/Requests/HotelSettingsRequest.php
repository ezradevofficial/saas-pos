<?php

namespace Modules\Hotel\Http\Requests;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Hotel\Models\Settings;
use Modules\Hotel\Support\Access;

/**
 * GET and PUT companies/{company}/hotel-settings (HOT-02): `hotel.settings.view`
 * to read, `hotel.settings.edit` to change, at the company; a company the
 * user holds nothing at is not found. PUT body: any of the settings (see
 * SettingsResource); money in minor units; levy tax codes and the room item
 * must belong to the company's tenant (checked under RLS); the room item is
 * a service or non-stock item.
 */
class HotelSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');
        $permission = $this->isMethod('GET') ? 'hotel.settings.view' : 'hotel.settings.edit';
        $resolver = app(ScopeResolver::class);
        $access = app(Access::class);
        $scope = Scope::company($company->id);

        // A company the user holds nothing at is not found; one they only read cannot be changed (403).
        abort_unless($resolver->can($this->user(), 'hotel.settings.view', $scope) || $resolver->can($this->user(), 'hotel.settings.edit', $scope)
            || $access->canCompany($this->user(), 'hotel.settings.view', $company->id) || $access->canCompany($this->user(), 'hotel.settings.edit', $company->id), 404);

        return $access->canCompany($this->user(), $permission, $company->id);
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [];
        }

        $company = $this->route('company');
        $time = ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $kind = ['sometimes', 'string', Rule::in(Settings::CHARGE_KINDS)];

        return [
            'check_in_time' => $time,
            'check_out_time' => $time,
            'night_audit_time' => $time,
            'early_checkin_fee_minor' => HotelRules::minor(false),
            'late_checkout_fee_minor' => HotelRules::minor(false),
            'fee_currency' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{3}$/', Rule::exists('tenant_currencies', 'code')->where('active', true)],
            'weekend_days' => ['sometimes', 'array', 'max:7'],
            'weekend_days.*' => ['integer', 'between:1,7', 'distinct'],
            'cancel_free_days' => ['sometimes', 'integer', 'between:0,365'],
            'cancel_charge_kind' => $kind,
            'cancel_charge_value' => HotelRules::minor(false),
            'noshow_charge_kind' => $kind,
            'noshow_charge_value' => HotelRules::minor(false),
            'deposit_percent' => ['sometimes', 'integer', 'between:0,100'],
            'require_guest_id' => ['sometimes', 'boolean'],
            'room_item_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('items', 'id')->whereIn('type', ['service', 'non_stock'])->whereNull('archived_at')],
            'levy_tax_code_ids' => ['sometimes', 'array', 'max:5'],
            'levy_tax_code_ids.*' => ['uuid', 'distinct', Rule::exists('tax_codes', 'id')->where('company_id', $company->id)->whereNull('archived_at')],
            'charge_limit_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999999999999'],
            'room_charge_method_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('payment_methods', 'id')->where('company_id', $company->id)->where('type', 'room_charge')],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty() || $this->isMethod('GET')) {
                return;
            }

            foreach (['cancel', 'noshow'] as $which) {
                $kind = $this->input("{$which}_charge_kind");
                $value = $this->input("{$which}_charge_value");

                if ($kind === 'percent' && $value !== null && (int) $value > 100) {
                    $validator->errors()->add("{$which}_charge_value", __('hotel.errors.percent_range'));
                }
            }
        }];
    }
}
