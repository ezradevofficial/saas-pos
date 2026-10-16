<?php

namespace App\Core\Platform\SupportAccess\Http\Controllers;

use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\SupportAccess\Http\Requests\AskSupportAccessRequest;
use App\Core\Platform\SupportAccess\Http\Requests\PlatformSupportRequest as SupportRequest;
use App\Core\Platform\SupportAccess\Models\PlatformSupportRequest;
use App\Core\Platform\SupportAccess\Models\SupportAccessGrant;
use App\Core\Platform\SupportAccess\SupportAccess;
use App\Core\Platform\Tenants\PlatformTenants;
use Illuminate\Http\JsonResponse;

/**
 * RBAC-13, ADM-03: support access from the console. Staff see their own
 * requests (platform owners see everyone's), ask a tenant, cancel a
 * pending request, open a session of an approved one (a one-time entry
 * code for the tenant web) and end it. The consent is the tenant's: every
 * step checks its grant in the tenant's context.
 */
class PlatformSupportController
{
    public function __construct(
        private readonly SupportAccess $support,
        private readonly PlatformTenants $tenants,
    ) {}

    public function index(SupportRequest $request): JsonResponse
    {
        $staff = $request->staff();
        $rows = PlatformSupportRequest::query()->with('staff')
            ->when($staff->role !== PlatformUser::ROLE_OWNER, fn ($q) => $q->where('platform_user_id', $staff->id))
            ->orderByDesc('requested_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (PlatformSupportRequest $r) => self::present($r, $staff))->all()]);
    }

    public function store(AskSupportAccessRequest $request, string $tenant): JsonResponse
    {
        $this->tenants->account404($tenant);
        $grant = $this->support->request($tenant, $request->staff(), (string) $request->validated('reason'), (string) $request->validated('mode'), (int) $request->validated('hours'));

        return response()->json(['data' => self::present($this->mirror($grant->id), $request->staff())], 201);
    }

    public function cancel(SupportRequest $request, string $supportRequest): JsonResponse
    {
        $this->support->cancel($this->mirror($supportRequest), $request->staff());

        return response()->json(['data' => self::present($this->mirror($supportRequest), $request->staff())]);
    }

    /** RBAC-13c: a one-time entry code (60 seconds) for the tenant web; the token itself never passes through the console. */
    public function session(SupportRequest $request, string $supportRequest): JsonResponse
    {
        $result = $this->support->openSession($this->mirror($supportRequest), $request->staff());

        return response()->json(['data' => [
            'entry_code' => $result['entry_code'],
            'entry_code_expires_at' => $result['expires_at'],
            'request' => self::present($this->mirror($supportRequest), $request->staff()),
        ]]);
    }

    public function end(SupportRequest $request, string $supportRequest): JsonResponse
    {
        $this->support->endByStaff($this->mirror($supportRequest), $request->staff());

        return response()->json(['data' => self::present($this->mirror($supportRequest), $request->staff())]);
    }

    private function mirror(string $id): PlatformSupportRequest
    {
        return PlatformSupportRequest::query()->find($id) ?? abort(404);
    }

    /** @return array<string, mixed> */
    public static function present(PlatformSupportRequest $request, PlatformUser $viewer): array
    {
        $active = $request->status === SupportAccessGrant::APPROVED && $request->expires_at?->isFuture();

        return [
            'id' => $request->id,
            'tenant_id' => $request->tenant_ref,
            'tenant_name' => $request->tenant_name,
            'staff' => ['id' => $request->platform_user_id, 'name' => $request->staff?->name],
            'mine' => $request->platform_user_id === $viewer->id,
            'reason' => $request->reason,
            'mode' => $request->mode,
            'hours' => $request->hours,
            'include_sensitive' => $request->include_sensitive,
            'status' => $request->status === SupportAccessGrant::APPROVED && ! $active ? SupportAccessGrant::EXPIRED : $request->status,
            'active' => $active,
            'requested_at' => $request->requested_at->toIso8601String(),
            'decided_at' => $request->decided_at?->toIso8601String(),
            'expires_at' => $request->expires_at?->toIso8601String(),
            'ended_at' => $request->ended_at?->toIso8601String(),
            'end_reason' => $request->end_reason,
        ];
    }
}
