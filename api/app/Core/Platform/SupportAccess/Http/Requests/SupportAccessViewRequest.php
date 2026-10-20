<?php

namespace App\Core\Platform\SupportAccess\Http\Requests;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;

/** RBAC-13b: see support access requests, sessions and their activity (`core.support_access.view`, tenant scope). */
class SupportAccessViewRequest extends FormRequest
{
    protected string $permission = 'core.support_access.view';

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can($this->permission, Scope::tenant());
    }

    public function rules(): array
    {
        return [];
    }

    public function actor(): User
    {
        /** @var User */
        return $this->user();
    }
}
