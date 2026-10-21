<?php

namespace App\Core\Sso;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Grants;
use App\Core\Rbac\Scope;
use App\Core\Sso\Domains\SsoDomains;
use App\Core\Sso\Models\SsoConnection;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * AUTH-11, ADR 023: the tenant's SSO choices, kept in tenants.settings.sso:
 *
 * - enforce: password sign-in is refused for users of the verified email
 *   domains (Owners keep it, RBAC-10), and so is OTP sign-up for them.
 *   Needs an enabled connection and a verified domain.
 * - jit_provisioning: a person with no account and no invitation is
 *   created with the default role at the default scope. Off by default.
 *   The default is checked as the actor (Grants::assertCanInvite), never an
 *   Owner role, and remembered with who set it (re-checked at use).
 *
 * Changes are audited as `core.sso.settings_update` (AUD-01).
 */
class SsoSettings
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
        private readonly Grants $grants,
        private readonly SsoDomains $domains,
    ) {}

    /** @return array{enforce: bool, jit_provisioning: bool, jit_role_id: ?string, jit_scope_type: ?string, jit_scope_id: ?string, jit_set_by: ?string} */
    public function values(?Tenant $tenant = null): array
    {
        $tenant ??= Tenant::query()->findOrFail($this->tenants->require());
        $sso = (array) (($tenant->settings ?? [])['sso'] ?? []);

        return [
            'enforce' => (bool) ($sso['enforce'] ?? false),
            'jit_provisioning' => (bool) ($sso['jit_provisioning'] ?? false),
            'jit_role_id' => $sso['jit_role_id'] ?? null,
            'jit_scope_type' => $sso['jit_scope_type'] ?? null,
            'jit_scope_id' => $sso['jit_scope_id'] ?? null,
            'jit_set_by' => $sso['jit_set_by'] ?? null,
        ];
    }

    public function enforced(): bool
    {
        return $this->values()['enforce'];
    }

    /**
     * @param  array{enforce?: bool, jit_provisioning?: bool, jit_role_id?: ?string, jit_scope_type?: ?string, jit_scope_id?: ?string}  $input
     * @return array<string, mixed>
     */
    public function update(User $actor, array $input): array
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($actor, $input) {
            $tenant = Tenant::query()->whereKey($this->tenants->require())->lockForUpdate()->firstOrFail();
            $before = $this->values($tenant);
            $after = $before;

            foreach (['enforce', 'jit_provisioning'] as $flag) {
                if (array_key_exists($flag, $input)) {
                    $after[$flag] = (bool) $input[$flag];
                }
            }

            if (array_key_exists('jit_role_id', $input)) {
                $after['jit_role_id'] = $input['jit_role_id'];
                $after['jit_scope_type'] = $input['jit_role_id'] === null ? null : ($input['jit_scope_type'] ?? Scope::TENANT);
                $after['jit_scope_id'] = $input['jit_role_id'] === null || $after['jit_scope_type'] === Scope::TENANT ? null : ($input['jit_scope_id'] ?? null);
            }

            $defaultChanged = [$after['jit_role_id'], $after['jit_scope_type'], $after['jit_scope_id']] !== [$before['jit_role_id'], $before['jit_scope_type'], $before['jit_scope_id']];

            if ($defaultChanged && $after['jit_role_id'] !== null) {
                $role = $this->grants->role($after['jit_role_id']);

                if ($role->is_owner) {
                    $message = __('sso.errors.jit_owner_role');

                    throw new ApiException(422, 'jit_owner_role', $message, ['jit_role_id' => [$message]]);
                }

                $scope = $this->grants->scope($after['jit_scope_type'], $after['jit_scope_id']);
                $this->grants->assertCanInvite($actor, $role, $scope);
                $after['jit_set_by'] = $actor->id;
            } elseif ($after['jit_role_id'] === null) {
                $after['jit_set_by'] = null;
            }

            if ($after['jit_provisioning'] && $after['jit_role_id'] === null) {
                $message = __('sso.errors.jit_role_required');

                throw new ApiException(422, 'jit_role_required', $message, ['jit_role_id' => [$message]]);
            }

            if ($after['enforce'] && ! $before['enforce']) {
                $this->assertEnforceable();
            }

            $settings = $tenant->settings ?? [];
            $settings['sso'] = $after;
            $tenant->settings = $settings;
            $tenant->save();

            if ($after !== $before) {
                $changed = array_keys(array_filter($after, fn ($value, $key) => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));
                $this->auditor->record('core.sso.settings_update', $tenant,
                    array_intersect_key($before, array_flip($changed)),
                    array_intersect_key($after, array_flip($changed)),
                );
            }

            return $after;
        });
    }

    /** Enforcing needs a way in: an enabled connection and a verified email domain. */
    private function assertEnforceable(): void
    {
        $live = SsoConnection::query()->where('enabled', true)->whereNull('archived_at')->exists();

        if (! $live || ! $this->domains->hasVerified()) {
            $message = __('sso.errors.enforce_needs_setup');

            throw new ApiException(422, 'enforce_needs_setup', $message, ['enforce' => [$message]]);
        }
    }
}
