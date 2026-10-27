<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Pharmacy\Models\Recall;

/** GET pharmacy/recalls (PHA-22): `pharmacy.recall.view`; `?status=` (open, lifted), `?item_id=`, `?search=` (batch number or reference), pages. */
class ListRecallsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'pharmacy.recall.view');
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::in([Recall::OPEN, Recall::LIFTED, 'all'])],
            'item_id' => ['sometimes', 'uuid', Rule::exists('items', 'id')],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
