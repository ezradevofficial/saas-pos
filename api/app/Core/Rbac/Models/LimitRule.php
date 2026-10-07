<?php

namespace App\Core\Rbac\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A numeric limit of a role, e.g. max discount percent (RBAC-06). */
class LimitRule extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected string $auditModule = 'rbac';

    protected string $auditResource = 'limit_rule';

    protected $fillable = ['role_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'decimal:4'];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
