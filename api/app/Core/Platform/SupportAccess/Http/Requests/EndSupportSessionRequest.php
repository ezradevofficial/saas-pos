<?php

namespace App\Core\Platform\SupportAccess\Http\Requests;

use App\Core\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** RBAC-13c: a support principal ends its own session; nobody else uses this route. */
class EndSupportSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->support_grant_id !== null;
    }

    public function rules(): array
    {
        return [];
    }
}
