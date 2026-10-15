<?php

namespace Modules\Manufacturing\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;

/** GET manufacturing/status (MFG-07, MFG-17): any of Manufacturing's view permissions somewhere. */
class StatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resolver = app(ScopeResolver::class);

        return collect(['manufacturing.bom.view', 'manufacturing.order.view', 'manufacturing.report.view'])
            ->contains(fn (string $permission) => $resolver->can($this->user(), $permission));
    }

    public function rules(): array
    {
        return [];
    }
}
