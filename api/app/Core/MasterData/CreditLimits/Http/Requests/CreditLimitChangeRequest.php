<?php

namespace App\Core\MasterData\CreditLimits\Http\Requests;

use App\Core\MasterData\CreditLimits\CreditLimitChange;
use App\Core\MasterData\CreditLimits\CreditLimitChangeAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET credit-limit-changes/{credit_limit_change}: one request, with its
 * flow's status and history (WF-10). Not seen: 404 (RBAC-04).
 */
class CreditLimitChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(app(CreditLimitChangeAccess::class)->view($this->user(), $this->change()), 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function change(): CreditLimitChange
    {
        return $this->route('credit_limit_change');
    }
}
