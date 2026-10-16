<?php

namespace App\Core\Api;

use App\Core\Api\Models\ApiKey;
use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Grants;
use App\Core\Rbac\RoleManager;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * API-02 (ADR 020): creating, changing, rotating and revoking a tenant's
 * API keys. Each key gets a service principal (a user with status
 * `service`) holding one role at one scope, granted with the same
 * anti-escalation check as a person's role (Grants); an owner role is
 * never given to a key. The plain key is returned once, by create and
 * rotate. Every change is audited on the key (`core.api_key.*`), never
 * with a secret or its hash.
 */
class ApiKeys
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Grants $grants,
        private readonly RoleManager $roles,
        private readonly Auditor $auditor,
        private readonly ApiScopes $scopes,
    ) {}

    /**
     * @param  array{name: string, role_id: string, scope_type: string, scope_id?: ?string, scopes: list<string>, expires_at?: ?string}  $data
     * @return array{key: ApiKey, plain: string}
     */
    public function create(User $actor, array $data): array
    {
        $role = $this->grants->role($data['role_id']);
        $scope = $this->grants->scope($data['scope_type'], $data['scope_id'] ?? null);

        if ($role->is_owner) {
            $message = __('api.errors.owner_role');

            throw new ApiException(422, 'api_key_owner_role', $message, ['role_id' => [$message]]);
        }

        $this->grants->assertCanGrant($actor, $role, $scope);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($actor, $data, $role, $scope) {
            $tenant = Tenant::query()->whereKey($this->tenants->require())->lockForUpdate()->firstOrFail();

            if (ApiKey::query()->whereNull('revoked_at')->count() >= (int) config('api.keys.max_active', 50)) {
                throw new ApiException(422, 'api_key_limit', __('api.errors.key_limit', ['max' => (int) config('api.keys.max_active', 50)]));
            }

            $token = ApiKeyToken::generate($tenant->is_sandbox ? ApiKeyToken::MODE_TEST : ApiKeyToken::MODE_LIVE);
            $principal = User::create([
                'name' => $data['name'],
                'password' => Str::random(64),
                'locale' => $tenant->default_locale,
                'status' => User::STATUS_SERVICE,
            ]);

            $key = ApiKey::create([
                'name' => $data['name'],
                'public_id' => $token->publicId,
                'mode' => $token->mode,
                'secret_hash' => $token->hash(),
                'principal_id' => $principal->id,
                'scopes' => $this->normalise($data['scopes']),
                'created_by' => $actor->id,
                'expires_at' => isset($data['expires_at']) ? CarbonImmutable::parse($data['expires_at']) : null,
            ]);

            $this->roles->createAssignment($principal, $role, $scope, $actor->id);
            $this->auditor->record('core.api_key.create', $key, null, [...$this->describe($key), 'role_id' => $role->id, 'scope_type' => $scope->type, 'scope_id' => $scope->id]);

            return ['key' => $key, 'plain' => $token->plain()];
        });
    }

    /** @param array{name?: string, scopes?: list<string>} $data */
    public function update(ApiKey $key, array $data): ApiKey
    {
        $this->assertActive($key);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($key, $data) {
            $key = ApiKey::query()->whereKey($key->id)->lockForUpdate()->firstOrFail();
            $before = $this->describe($key);

            if (array_key_exists('name', $data)) {
                $key->name = $data['name'];
                User::query()->whereKey($key->principal_id)->firstOrFail()->forceFill(['name' => $data['name']])->save();
            }

            if (array_key_exists('scopes', $data)) {
                $key->scopes = $this->normalise($data['scopes']);
            }

            if ($key->isDirty()) {
                $key->save();
                $this->auditor->record('core.api_key.update', $key, array_intersect_key($before, $key->getChanges()), array_intersect_key($this->describe($key), $key->getChanges()));
                $this->scopes->flush();
            }

            return $key;
        });
    }

    /**
     * A new secret for the same key id; the previous one keeps working for
     * $overlapHours (0 ends it now).
     *
     * @return array{key: ApiKey, plain: string}
     */
    public function rotate(ApiKey $key, int $overlapHours): array
    {
        $this->assertActive($key);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($key, $overlapHours) {
            $key = ApiKey::query()->whereKey($key->id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();
            $token = ApiKeyToken::generate($key->mode, $key->public_id);

            $key->forceFill([
                'previous_secret_hash' => $overlapHours > 0 ? $key->secret_hash : null,
                'previous_expires_at' => $overlapHours > 0 ? $now->addHours($overlapHours) : null,
                'secret_hash' => $token->hash(),
                'rotated_at' => $now,
            ])->save();

            $this->auditor->record('core.api_key.rotate', $key, null, [
                'overlap_hours' => $overlapHours,
                'previous_expires_at' => $key->previous_expires_at?->toIso8601String(),
            ]);

            return ['key' => $key, 'plain' => $token->plain()];
        });
    }

    /** Revoked at once, both secrets; its principal loses its role. */
    public function revoke(User $actor, ApiKey $key): ApiKey
    {
        if ($key->revoked_at !== null) {
            throw new ApiException(409, 'api_key_revoked', __('api.errors.key_revoked'));
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($actor, $key) {
            $key = ApiKey::query()->whereKey($key->id)->lockForUpdate()->firstOrFail();
            $key->forceFill([
                'revoked_at' => CarbonImmutable::now(),
                'revoked_by' => $actor->id,
                'previous_secret_hash' => null,
                'previous_expires_at' => null,
            ])->save();

            $key->assignment()->get()->each->delete();
            $this->auditor->record('core.api_key.revoke', $key, null, $this->describe($key));
            $this->scopes->flush();

            return $key;
        });
    }

    private function assertActive(ApiKey $key): void
    {
        if (! $key->isUsable()) {
            throw new ApiException(409, $key->revoked_at !== null ? 'api_key_revoked' : 'api_key_expired', __($key->revoked_at !== null ? 'api.errors.key_revoked' : 'api.errors.key_expired'));
        }
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    private function normalise(array $scopes): array
    {
        $scopes = array_values(array_unique($scopes));
        sort($scopes);

        return $scopes;
    }

    /** @return array<string, mixed> what the audit log keeps of a key (never a secret or hash) */
    private function describe(ApiKey $key): array
    {
        return [
            'name' => $key->name,
            'mode' => $key->mode,
            'public_id' => $key->public_id,
            'scopes' => $key->scopeList(),
            'expires_at' => $key->expires_at?->toIso8601String(),
            'revoked_at' => $key->revoked_at?->toIso8601String(),
        ];
    }
}
