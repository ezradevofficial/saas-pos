<?php

namespace Modules\Stores\Http\Requests;

use App\Core\Rbac\ScopeResolver;
use Illuminate\Foundation\Http\FormRequest;

/** GET stores/status (STR-07, STR-08): for anyone who reads something of Stores somewhere. */
class StatusRequest extends FormRequest
{
    public const PERMISSIONS = ['stores.requisition.view', 'stores.issue.view', 'stores.return.view', 'stores.ledger.view', 'stores.report.view'];

    public function authorize(): bool
    {
        $resolver = app(ScopeResolver::class);

        foreach (self::PERMISSIONS as $permission) {
            if ($resolver->can($this->user(), $permission)) {
                return true;
            }
        }

        return false;
    }

    public function rules(): array
    {
        return [];
    }
}
