<?php

namespace App\Core\Platform\Models;

use App\Core\Platform\PlatformDatabase;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Platform staff (SUB-01, ADM-07 minimal): our own team, not tenant users.
 * Global table without tenant_id or RLS (ADR 013). Two-factor is required:
 * until an authenticator app is confirmed, a staff token can only enrol.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $password
 * @property string $role
 * @property string $status
 * @property string|null $two_factor_secret
 * @property Carbon|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_used_step
 * @property int $failed_sign_ins
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_sign_in_at
 */
class PlatformUser extends Authenticatable
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEACTIVATED = 'deactivated';

    /** Platform roles (minimal; the full staff RBAC of ADM-07 is phase 8). */
    public const ROLE_OWNER = 'owner';

    public const ROLE_BILLING = 'billing';

    public const ROLE_SUPPORT = 'support';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_BILLING, self::ROLE_SUPPORT];

    /** What each role may do. */
    private const ABILITIES = [
        'catalogue.view' => [self::ROLE_OWNER, self::ROLE_BILLING, self::ROLE_SUPPORT],
        'catalogue.manage' => [self::ROLE_OWNER, self::ROLE_BILLING],
    ];

    /** ADR 013: staff writes go through the platform role; public reads use ::on(PlatformDatabase::READ). */
    protected $connection = PlatformDatabase::CONNECTION;

    protected $table = 'platform_users';

    protected $fillable = ['name', 'email', 'role'];

    protected $hidden = ['password', 'two_factor_secret', 'two_factor_last_used_step', 'setup_token_hash'];

    /** The token this request was authenticated with (set by PlatformTokens). */
    private ?PlatformAccessToken $currentToken = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
            'failed_sign_ins' => 'integer',
            'locked_until' => 'datetime',
            'last_sign_in_at' => 'datetime',
            'setup_expires_at' => 'datetime',
        ];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(PlatformAccessToken::class);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /** May this staff member do $ability (`catalogue.view`, `catalogue.manage`)? */
    public function allows(string $ability): bool
    {
        return $this->status === self::STATUS_ACTIVE && in_array($this->role, self::ABILITIES[$ability] ?? [], true);
    }

    /** @return list<string> */
    public function abilities(): array
    {
        return array_keys(array_filter(self::ABILITIES, fn (array $roles) => in_array($this->role, $roles, true)));
    }

    public function withAccessToken(?PlatformAccessToken $token): static
    {
        $this->currentToken = $token;

        return $this;
    }

    public function currentAccessToken(): ?PlatformAccessToken
    {
        return $this->currentToken;
    }
}
