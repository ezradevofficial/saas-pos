<?php

namespace App\Core\Rbac\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\AccessChangingBuilder;
use App\Core\Rbac\AccessVersion;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether a tenant has an optional module active (RBAC-08). SUB-07: a
 * module switched off keeps its data; `switched_off_at` dates it and
 * `purge_flagged_at` marks it reported for purge (billing:purge-report).
 */
class TenantModule extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    protected string $auditResource = 'module';

    protected $fillable = ['module', 'status', 'activated_at', 'deactivated_at', 'switched_off_at', 'purge_flagged_at'];

    /** RBAC-12: bulk writes move the tenant's access version on, as single ones do (AccessChangingBuilder). */
    public function newEloquentBuilder($query): AccessChangingBuilder
    {
        return new AccessChangingBuilder($query);
    }

    protected static function booted(): void
    {
        // Stage 7B performance review: ModuleRegistry memoises isActive; any change forgets it.
        $flush = fn () => app(ModuleRegistry::class)->flush();
        static::saved($flush);
        static::deleted($flush);

        // NFR-02, RBAC-08: modules and subscriptions decide the permissions; cached access data is dropped with a version bump.
        $bump = fn (self $module) => app(AccessVersion::class)->bump((string) $module->tenant_id);
        static::saved($bump);
        static::deleted($bump);
    }

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'switched_off_at' => 'datetime',
            'purge_flagged_at' => 'datetime',
        ];
    }
}
