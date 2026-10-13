<?php

namespace App\Core\Limits\Http\Requests;

use App\Core\Identity\Models\User;
use App\Core\Rbac\OwnerGuard;
use Illuminate\Foundation\Http\FormRequest;

/**
 * LIM-02: the plan's usage, its overages and their cost are the Owners'
 * (RBAC-10): only an active Owner of the tenant sees them.
 */
class UsageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && app(OwnerGuard::class)->isOwner($user);
    }

    public function rules(): array
    {
        return [];
    }
}
