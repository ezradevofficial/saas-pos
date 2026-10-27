<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Pharmacy\Models\ItemProfile;

/**
 * GET and PUT pharmacy/items/{item}/profile (PHA-03, PHA-20, PHA-21):
 * `pharmacy.item.view` to read, `pharmacy.item.configure` to change. PUT
 * body: `{class?, product_kind?, batch_tracked?, restricted_sale?,
 * usage_notes?, schedule_note?, withdrawal_days?}`. The schedule note is
 * free text: the regulator's category in words is never validated here.
 */
class ItemProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), $this->isMethod('GET') ? 'pharmacy.item.view' : 'pharmacy.item.configure');
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return [];
        }

        return [
            'class' => ['sometimes', 'string', Rule::in(ItemProfile::CLASSES)],
            'product_kind' => ['sometimes', 'string', Rule::in(ItemProfile::KINDS)],
            'batch_tracked' => ['sometimes', 'boolean'],
            'restricted_sale' => ['sometimes', 'boolean'],
            'usage_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'schedule_note' => ['sometimes', 'nullable', 'string', 'max:200'],
            'withdrawal_days' => ['sometimes', 'nullable', 'integer', 'between:0,3650'],
        ];
    }
}
