<?php

namespace Modules\Pharmacy\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;

/** GET pharmacy/recalls/{recall} and /customers (PHA-22, PHA-23): `pharmacy.recall.view` (recalls are tenant-wide). */
class ShowRecallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ScopeResolver::class)->can($this->user(), 'pharmacy.recall.view');
    }

    public function rules(): array
    {
        return [];
    }
}
