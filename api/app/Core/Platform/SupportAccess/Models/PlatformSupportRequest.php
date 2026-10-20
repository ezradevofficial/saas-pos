<?php

namespace App\Core\Platform\SupportAccess\Models;

use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformDatabase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * RBAC-13: the console's mirror of a support request (global, platform
 * role only, ADR 013). Its id is the grant's id in the tenant; the
 * consent itself is the tenant's record (SupportAccessGrant), which every
 * session checks. Kept in step by SupportAccess.
 *
 * @property string $id
 * @property string $tenant_ref
 * @property string $tenant_name
 * @property string $platform_user_id
 * @property string $reason
 * @property string $mode
 * @property int $hours
 * @property bool $include_sensitive
 * @property string $status
 * @property Carbon $requested_at
 * @property Carbon|null $decided_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 */
class PlatformSupportRequest extends Model
{
    protected $connection = PlatformDatabase::CONNECTION;

    protected $table = 'platform_support_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'tenant_ref', 'tenant_name', 'platform_user_id', 'reason', 'mode', 'hours', 'include_sensitive', 'status',
        'requested_at', 'decided_at', 'expires_at', 'ended_at', 'end_reason'];

    protected function casts(): array
    {
        return [
            'hours' => 'integer',
            'include_sensitive' => 'boolean',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'platform_user_id');
    }
}
