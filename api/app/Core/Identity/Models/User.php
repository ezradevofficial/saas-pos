<?php

namespace App\Core\Identity\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * A person who signs in (AUTH-01). Belongs to one tenant; email and phone
 * (E.164) are unique across tenants. Users are deactivated, never deleted
 * (AUTH-13).
 */
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use Audited, BelongsToTenant, HasApiTokens, HasFactory, HasUuids, Notifiable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEACTIVATED = 'deactivated';

    protected array $auditHidden = ['password', 'two_factor_secret', 'two_factor_last_used_step'];

    protected $fillable = [
        'tenant_id', 'name', 'email', 'phone', 'password', 'locale', 'status',
        'email_verified_at', 'phone_verified_at',
    ];

    protected $hidden = ['password', 'two_factor_secret', 'two_factor_last_used_step'];

    protected $attributes = [
        'locale' => 'en',
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
            'locked_until' => 'datetime',
            'last_sign_in_at' => 'datetime',
            'failed_sign_ins' => 'integer',
            'is_platform_staff' => 'boolean',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function preferredLocale(): string
    {
        return $this->locale;
    }

    public function routeNotificationForSms(): ?string
    {
        return $this->phone;
    }

    /**
     * A bearer token for one signed-in device (AUTH-09), bound to the
     * user's tenant. Returns `id|secret`; only the secret's hash is stored.
     *
     * @param  list<string>  $abilities  ['*'], or TwoFactor::ENROL_ABILITY alone (AUTH-03)
     */
    public function createDeviceToken(string $name, ?string $ip, ?string $userAgent, array $abilities = ['*']): NewAccessToken
    {
        $plain = $this->generateTokenString();

        $token = $this->tokens()->create([
            'tenant_id' => $this->tenant_id,
            'name' => Str::limit($name, 100, ''),
            'token' => hash('sha256', $plain),
            'abilities' => $abilities,
            'ip' => $ip,
            'user_agent' => $userAgent,
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plain);
    }
}
