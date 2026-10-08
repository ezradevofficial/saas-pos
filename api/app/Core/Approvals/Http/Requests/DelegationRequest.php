<?php

namespace App\Core\Approvals\Http\Requests;

use App\Core\Approvals\Models\ApprovalDelegation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * APR-06: the signed-in user's own delegations (GET me/delegations, POST
 * me/delegations/{delegation}/revoke). Every user may delegate their own
 * approvals; another user's delegation is not found.
 */
class DelegationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $delegation = $this->route('delegation');

        abort_if($delegation instanceof ApprovalDelegation && $delegation->from_user_id !== $this->user()->id, 404);

        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [];
    }

    public function delegation(): ApprovalDelegation
    {
        return $this->route('delegation');
    }
}
