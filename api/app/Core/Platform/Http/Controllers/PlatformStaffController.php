<?php

namespace App\Core\Platform\Http\Controllers;

use App\Core\Http\ApiException;
use App\Core\Platform\Http\Requests\InviteStaffRequest;
use App\Core\Platform\Http\Requests\ManageStaffRequest;
use App\Core\Platform\Http\Requests\UpdateStaffRequest;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformAuditor;
use App\Core\Platform\PlatformAuth;
use App\Core\Platform\PlatformDatabase;
use App\Core\Platform\PlatformTokens;
use App\Core\Platform\SupportAccess\SupportAccess;
use Illuminate\Http\JsonResponse;

/**
 * ADM-07: platform owners manage staff. They list them, invite one (a
 * one-time setup link, shown once), change a role, and deactivate or
 * reactivate an account. The last active platform owner is never demoted
 * or deactivated. Deactivating revokes every token. Every change is
 * audited in the platform chain.
 */
class PlatformStaffController
{
    public function __construct(
        private readonly PlatformAuth $auth,
        private readonly PlatformAuditor $audit,
        private readonly PlatformTokens $tokens,
        private readonly SupportAccess $support,
    ) {}

    public function index(ManageStaffRequest $request): JsonResponse
    {
        return response()->json(['data' => PlatformUser::query()->orderBy('name')->get()->map(fn (PlatformUser $u) => self::present($u))->all()]);
    }

    /** A new staff member: the setup link is in the answer once (ADR 013). */
    public function store(InviteStaffRequest $request): JsonResponse
    {
        $token = $this->auth->invite(mb_strtolower(trim((string) $request->validated('email'))), (string) $request->validated('name'), (string) $request->validated('role'));
        $user = PlatformUser::query()->where('email', mb_strtolower(trim((string) $request->validated('email'))))->firstOrFail();

        return response()->json(['data' => [
            ...self::present($user),
            'setup_url' => rtrim((string) config('platform.url'), '/').'/platform/setup/'.$token,
            'setup_expires_hours' => PlatformAuth::SETUP_HOURS,
        ]], 201);
    }

    public function update(UpdateStaffRequest $request, string $staff): JsonResponse
    {
        $role = (string) $request->validated('role');

        $changed = false;
        $user = PlatformDatabase::transaction(function () use ($staff, $role, &$changed) {
            $user = $this->locked($staff);
            $before = $user->role;

            if ($before === $role) {
                return $user;
            }

            $changed = true;

            if ($before === PlatformUser::ROLE_OWNER) {
                $this->assertOtherOwner($user);
            }

            $user->fill(['role' => $role])->save();
            $this->audit->record('platform.staff.role_change', $user, ['role' => $before], ['role' => $role]);

            return $user;
        });

        // RBAC-13 (review H2): another role ends the staff member's support access everywhere.
        if ($changed) {
            $this->support->endForStaff($user);
        }

        return response()->json(['data' => self::present($user)]);
    }

    public function deactivate(ManageStaffRequest $request, string $staff): JsonResponse
    {
        $user = PlatformDatabase::transaction(function () use ($request, $staff) {
            $user = $this->locked($staff);

            if ($user->id === $request->staff()?->id) {
                throw new ApiException(422, 'cannot_deactivate_self', __('platform.staff.cannot_deactivate_self'));
            }

            if ($user->status === PlatformUser::STATUS_DEACTIVATED) {
                return $user;
            }

            if ($user->role === PlatformUser::ROLE_OWNER) {
                $this->assertOtherOwner($user);
            }

            $before = $user->status;
            $user->forceFill(['status' => PlatformUser::STATUS_DEACTIVATED])->save();
            $this->tokens->revokeAll($user);
            $this->audit->record('platform.staff.deactivate', $user, ['status' => $before], ['status' => $user->status]);

            return $user;
        });

        // RBAC-13 (review H2): a deactivated staff member's support access ends everywhere.
        $this->support->endForStaff($user);

        return response()->json(['data' => self::present($user)]);
    }

    /** A deactivated account comes back active when two-factor is set up, else pending (it must enrol). */
    public function reactivate(ManageStaffRequest $request, string $staff): JsonResponse
    {
        $user = PlatformDatabase::transaction(function () use ($staff) {
            $user = $this->locked($staff);

            if ($user->status !== PlatformUser::STATUS_DEACTIVATED) {
                return $user;
            }

            $user->forceFill(['status' => $user->hasTwoFactor() ? PlatformUser::STATUS_ACTIVE : PlatformUser::STATUS_PENDING])->save();
            $this->audit->record('platform.staff.reactivate', $user, ['status' => PlatformUser::STATUS_DEACTIVATED], ['status' => $user->status]);

            return $user;
        });

        return response()->json(['data' => self::present($user)]);
    }

    private function locked(string $id): PlatformUser
    {
        return PlatformUser::query()->whereKey($id)->lockForUpdate()->first() ?? abort(404);
    }

    /** ADM-07: the platform always keeps an active owner (serialised by locking every owner row). */
    private function assertOtherOwner(PlatformUser $user): void
    {
        $others = PlatformUser::query()->where('role', PlatformUser::ROLE_OWNER)->lockForUpdate()->get()
            ->filter(fn (PlatformUser $u) => $u->id !== $user->id && $u->status === PlatformUser::STATUS_ACTIVE);

        if ($others->isEmpty()) {
            throw new ApiException(422, 'last_platform_owner', __('platform.staff.last_owner'));
        }
    }

    /** @return array<string, mixed> */
    public static function present(PlatformUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'two_factor' => $user->hasTwoFactor(),
            'last_sign_in_at' => $user->last_sign_in_at?->toIso8601String(),
        ];
    }
}
