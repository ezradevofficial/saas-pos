<?php

namespace App\Core\DataExport\Http\Requests;

use App\Core\Identity\Models\User;
use App\Core\Rbac\OwnerGuard;
use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * EXP-02: full tenant exports are for Owners only: an active Owner of the
 * tenant (RBAC-10) holding `core.tenant.export` at tenant scope (seeded to
 * the Owner template only). Both are required, so a custom role given the
 * permission still cannot export the whole tenant.
 */
class TenantExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->can('core.tenant.export', Scope::tenant())
            && app(OwnerGuard::class)->isOwner($user);
    }

    public function rules(): array
    {
        return [];
    }
}
