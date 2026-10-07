<?php

namespace App\Core\Rbac\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A field a role sees as hidden or read-only on a resource (RBAC-05). */
class FieldRule extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const HIDDEN = 'hidden';

    public const READONLY = 'readonly';

    protected string $auditModule = 'rbac';

    protected string $auditResource = 'field_rule';

    protected $fillable = ['role_id', 'resource', 'field', 'mode'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
