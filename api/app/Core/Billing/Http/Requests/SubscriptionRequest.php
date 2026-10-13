<?php

namespace App\Core\Billing\Http\Requests;

use App\Core\Identity\Models\User;
use App\Core\Rbac\OwnerGuard;
use App\Core\Rbac\Scope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * SUB-02..SUB-06: the subscription is tenant-wide. Seeing it takes
 * `core.subscription.view`, paying and saving methods
 * `core.subscription.manage` (tenant scope); changing the plan is the
 * Owner's (`$ownerOnly`).
 */
class SubscriptionRequest extends FormRequest
{
    protected string $permission = 'core.subscription.view';

    protected bool $ownerOnly = false;

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User || ! $user->can($this->permission, Scope::tenant())) {
            return false;
        }

        return ! $this->ownerOnly || app(OwnerGuard::class)->isOwner($user);
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
