<?php

namespace App\Core\Platform\Models;

use App\Core\Platform\PlatformDatabase;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Platform staff (SUB-01, ADM-07): our own team, not tenant users.
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

    /** ADM-07: staff roles. The platform owner manages staff. */
    public const ROLE_OWNER = 'owner';

    public const ROLE_SUPPORT = 'support';

    public const ROLE_SALES = 'sales';

    public const ROLE_FINANCE = 'finance';

    public const ROLE_COMPLIANCE = 'compliance';

    public const ROLE_ENGINEERING = 'engineering';

    public const ROLES = [self::ROLE_OWNER, self::ROLE_SUPPORT, self::ROLE_SALES, self::ROLE_FINANCE, self::ROLE_COMPLIANCE, self::ROLE_ENGINEERING];

    /**
     * ADM-07 (docs/modules/platform-admin.md): what each role may do,
     * `platform.<resource>.<action>`. Code, not data: staff are few and
     * there are no custom staff roles.
     */
    public const PERMISSIONS = [
        'platform.tenants.view' => self::ROLES,
        // Review L3: finding a tenant by its Owner's email (personal data), audited.
        'platform.tenants.owner_search' => [self::ROLE_OWNER, self::ROLE_SUPPORT, self::ROLE_COMPLIANCE],
        // Suspend, reactivate, extend a trial, resend the Owner invitation.
        'platform.tenants.manage' => [self::ROLE_OWNER, self::ROLE_SUPPORT, self::ROLE_FINANCE],
        // ONB-08: create a tenant for a customer (field sales, support).
        'platform.tenants.create' => [self::ROLE_OWNER, self::ROLE_SUPPORT, self::ROLE_SALES],
        'platform.catalogue.view' => self::ROLES,
        'platform.catalogue.manage' => [self::ROLE_OWNER, self::ROLE_FINANCE],
        // ADR 016: subscriptions, invoices and billing settings (view); grace override,
        // bank transfers marked paid and billing settings (manage).
        'platform.billing.view' => [self::ROLE_OWNER, self::ROLE_SUPPORT, self::ROLE_SALES, self::ROLE_FINANCE, self::ROLE_COMPLIANCE],
        'platform.billing.manage' => [self::ROLE_OWNER, self::ROLE_FINANCE],
        // ADM-02: Enterprise deals, drafted by sales or finance and approved by finance.
        'platform.deals.manage' => [self::ROLE_OWNER, self::ROLE_SALES, self::ROLE_FINANCE],
        'platform.deals.approve' => [self::ROLE_OWNER, self::ROLE_FINANCE],
        // RBAC-13: ask a tenant for time-limited access.
        'platform.support.request' => [self::ROLE_OWNER, self::ROLE_SUPPORT, self::ROLE_ENGINEERING],
        // ADM-04: country pack releases; publishing needs two staff.
        'platform.packs.view' => [self::ROLE_OWNER, self::ROLE_COMPLIANCE, self::ROLE_ENGINEERING],
        'platform.packs.manage' => [self::ROLE_OWNER, self::ROLE_COMPLIANCE, self::ROLE_ENGINEERING],
        'platform.packs.approve' => [self::ROLE_OWNER, self::ROLE_COMPLIANCE, self::ROLE_ENGINEERING],
        'platform.packs.publish' => [self::ROLE_OWNER, self::ROLE_COMPLIANCE],
        // ADM-06: revenue and usage reports.
        'platform.reports.view' => [self::ROLE_OWNER, self::ROLE_SALES, self::ROLE_FINANCE, self::ROLE_COMPLIANCE],
        'platform.staff.manage' => [self::ROLE_OWNER],
        // ADM-05: the marketplace review queue (view); approve, reject, suspend and reinstate apps
        // (review, a security review: compliance and engineering).
        'platform.marketplace.view' => [self::ROLE_OWNER, self::ROLE_SUPPORT, self::ROLE_FINANCE, self::ROLE_COMPLIANCE, self::ROLE_ENGINEERING],
        'platform.marketplace.review' => [self::ROLE_OWNER, self::ROLE_COMPLIANCE, self::ROLE_ENGINEERING],
        // MKT-03: developers' payable balances and manual payouts.
        'platform.marketplace.payouts' => [self::ROLE_OWNER, self::ROLE_FINANCE],
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

    /** ADM-07: may this staff member do $permission (`platform.catalogue.manage`)? */
    public function allows(string $permission): bool
    {
        return $this->status === self::STATUS_ACTIVE && in_array($this->role, self::PERMISSIONS[$permission] ?? [], true);
    }

    /** @return list<string> the permissions of the staff member's role */
    public function abilities(): array
    {
        return array_keys(array_filter(self::PERMISSIONS, fn (array $roles) => in_array($this->role, $roles, true)));
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
