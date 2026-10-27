<?php

namespace Modules\Salon\Http\Requests;

use App\Core\Rbac\Scope;
use Illuminate\Validation\Rule;

/** PUT salon/companies/{company}/settings (SAL-10, SAL-11, SAL-14): `salon.settings.edit` at the company. */
class UpdateSettingsRequest extends SalonRequest
{
    public function rules(): array
    {
        return [
            'slot_minutes' => ['sometimes', 'required', 'integer', 'in:5,10,15,20,30,60'],
            'cancel_window_hours' => ['sometimes', 'required', 'integer', 'between:0,720'],
            'late_cancel_fee_percent' => ['sometimes', 'required', 'numeric', 'between:0,100'],
            'no_show_fee_percent' => ['sometimes', 'required', 'numeric', 'between:0,100'],
            'deposit_percent' => ['sometimes', 'required', 'numeric', 'between:0,100'],
            'deposit_online_required' => ['sometimes', 'required', 'boolean'],
            'hold_minutes' => ['sometimes', 'required', 'integer', 'between:5,1440'],
            'reminder_hours' => ['sometimes', 'required', 'integer', 'between:1,168'],
            'guest_retention_days' => ['sometimes', 'required', 'integer', 'between:30,1825'],
            'tip_item_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('items', 'id')],
            'fee_item_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('items', 'id')],
            'commission_item_code' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }

    protected function target(): ?Scope
    {
        return Scope::company($this->route('company')->id);
    }
}
