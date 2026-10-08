<?php

namespace App\Core\Approvals\Http\Requests;

use App\Core\Approvals\ApprovalAccess;
use App\Core\Approvals\Models\ApprovalRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * APR-04: one approval request (`{approval}`, bound under row-level
 * security). Anyone who may not see it gets 404 (ApprovalAccess::sees);
 * what they may do is checked by the action itself.
 */
class ApprovalItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(app(ApprovalAccess::class)->sees($this->user(), $this->approval()), 404);

        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function approval(): ApprovalRequest
    {
        return $this->route('approval');
    }
}
