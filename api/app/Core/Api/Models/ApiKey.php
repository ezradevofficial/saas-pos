<?php

namespace App\Core\Api\Models;

use App\Core\Api\ApiKeyToken;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Sanctum\Contracts\HasAbilities;

/**
 * API-02: a tenant's API key, a service principal (docs/adr/020). The key
 * acts as its principal user (status `service`), which holds one scoped
 * role assignment; `scopes` (`module.resource.read|write`) limit it
 * further. Only hashes of the secret are kept: the current one and, until
 * a rotation's overlap ends, the previous one. Revoked, never deleted.
 *
 * It is also the principal's current access token (Sanctum's
 * HasAbilities), so `EnsureFullAccessToken` and `tokenCan()` see a full
 * token; what a key may do is decided by its role and scopes alone.
 */
class ApiKey extends Model implements HasAbilities
{
    use BelongsToTenant, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'api_keys';

    protected $fillable = ['name', 'public_id', 'mode', 'secret_hash', 'principal_id', 'scopes', 'created_by', 'expires_at'];

    protected $hidden = ['secret_hash', 'previous_secret_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'immutable_datetime',
            'previous_expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'rotated_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'principal_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** RBAC-04: the one role assignment the key's principal holds. */
    public function assignment(): HasOne
    {
        return $this->hasOne(RoleAssignment::class, 'user_id', 'principal_id');
    }

    public function status(?CarbonImmutable $at = null): string
    {
        $at ??= CarbonImmutable::now();

        return match (true) {
            $this->revoked_at !== null => self::STATUS_REVOKED,
            $this->expires_at !== null && $this->expires_at->lte($at) => self::STATUS_EXPIRED,
            default => self::STATUS_ACTIVE,
        };
    }

    public function isUsable(?CarbonImmutable $at = null): bool
    {
        return $this->status($at) === self::STATUS_ACTIVE;
    }

    /** The current secret, or the previous one while its rotation overlap lasts (constant-time compare). */
    public function matchesSecret(string $secret, ?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();
        $hash = ApiKeyToken::hashSecret($secret);
        $current = hash_equals($this->secret_hash, $hash);
        $previous = $this->previous_secret_hash !== null
            && $this->previous_expires_at !== null
            && $this->previous_expires_at->gt($at)
            && hash_equals($this->previous_secret_hash, $hash);

        return $current || $previous;
    }

    public function display(): string
    {
        return ApiKeyToken::display($this->mode, $this->public_id);
    }

    /** @return list<string> */
    public function scopeList(): array
    {
        return array_values(array_filter((array) $this->scopes, 'is_string'));
    }

    public function can($ability)
    {
        return true;
    }

    public function cant($ability)
    {
        return false;
    }
}
