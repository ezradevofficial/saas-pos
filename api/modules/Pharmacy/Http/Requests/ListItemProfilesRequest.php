<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Pharmacy\Models\ItemProfile;

/**
 * GET pharmacy/items (PHA-03): `pharmacy.item.view`; the items with a
 * pharmacy profile; `?class=`, `?product_kind=`, `?search=` (name or code),
 * pages.
 */
class ListItemProfilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'pharmacy.item.view');
    }

    public function rules(): array
    {
        return [
            'class' => ['sometimes', 'string', Rule::in(ItemProfile::CLASSES)],
            'product_kind' => ['sometimes', 'string', Rule::in(ItemProfile::KINDS)],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
