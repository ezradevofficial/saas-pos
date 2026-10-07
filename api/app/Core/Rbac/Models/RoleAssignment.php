<?php

namespace App\Core\Rbac\Models;

use App\Core\Audit\Audited;
use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role held by a user at a scope (RBAC-04). For the tenant scope,
 * scope_id is the tenant id. Created and deleted (a link, not a business
 * record); both are audited as `rbac.assignment.*` (RBAC-12).
 */
class RoleAssignment extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected string $auditModule = 'rbac';

    protected string $auditResource = 'assignment';

    protected $fillable = ['tenant_id', 'user_id', 'role_id', 'scope_type', 'scope_id', 'created_by'];

    protected static function booted(): void
    {
        static::creating(function (self $assignment) {
            // The tenant scope always points at the tenant itself.
            if ($assignment->scope_type === Scope::TENANT && $assignment->scope_id === null) {
                $assignment->scope_id = $assignment->tenant_id;
            }
        });

        static::deleted(function (self $assignment) {
            app(Auditor::class)->record(
                'rbac.assignment.delete',
                $assignment,
                $assignment->only(['user_id', 'role_id', 'scope_type', 'scope_id']),
            );
        });
    }

    /** The deletion and its audit entry commit or roll back together. */
    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(fn () => parent::delete());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function scope(): Scope
    {
        return Scope::of($this->scope_type, $this->scope_id);
    }
}
