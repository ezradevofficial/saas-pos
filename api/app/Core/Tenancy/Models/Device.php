<?php

namespace App\Core\Tenancy\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Policies\DevicePolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * A POS device paired to a location (TEN-05). Retired by status, not
 * archived. A paired device signs in with its own token (ability `device`).
 */
#[UsePolicy(DevicePolicy::class)]
class Device extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasApiTokens, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_UNPAIRED = 'unpaired';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_UNPAIRED];

    /** The only ability a device token carries. */
    public const TOKEN_ABILITY = 'device';

    protected $fillable = ['tenant_id', 'location_id', 'name', 'code', 'status'];

    protected $attributes = ['status' => self::STATUS_PENDING];

    protected $hidden = ['pairing_code_hash'];

    protected function casts(): array
    {
        return [
            'pairing_code_expires_at' => 'datetime',
            'paired_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** RBAC-04: permission checks on a device apply at its location. */
    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    /**
     * A bearer token for this device, bound to its tenant. Returns
     * `id|secret`; only the secret's hash is stored.
     */
    public function issueToken(string $name, ?string $ip, ?string $userAgent): NewAccessToken
    {
        $plain = $this->generateTokenString();

        $token = $this->tokens()->create([
            'tenant_id' => $this->tenant_id,
            'name' => Str::limit($name, 100, ''),
            'token' => hash('sha256', $plain),
            'abilities' => [self::TOKEN_ABILITY],
            'ip' => $ip,
            'user_agent' => $userAgent,
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plain);
    }
}
