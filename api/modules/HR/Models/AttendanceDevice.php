<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Contracts\ServiceTokenable;
use App\Core\Identity\Models\PersonalAccessToken;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * HR-24: a biometric attendance terminal (or a vendor's bridge) at a
 * location. Not a core device: it never counts or bills as one. Its bearer
 * token (ability `hr.attendance_device`) is accepted only while it is
 * active (ServiceTokenable).
 */
class AttendanceDevice extends Model implements HasScope, ServiceTokenable
{
    use Audited, BelongsToTenant, HasApiTokens, HasUuids;

    public const TOKEN_ABILITY = 'hr.attendance_device';

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    public const RETIRED = 'retired';

    protected $table = 'hr_attendance_devices';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'attendance_device';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_seen_at' => 'immutable_datetime'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function acceptsTokens(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** A new bearer token (`id|secret`, shown once); only its hash is stored. */
    public function issueToken(): NewAccessToken
    {
        $plain = Str::random(48);

        /** @var PersonalAccessToken $token */
        $token = $this->tokens()->create([
            'tenant_id' => $this->tenant_id,
            'name' => Str::limit('hr-attendance:'.$this->name, 100, ''),
            'token' => hash('sha256', $plain),
            'abilities' => [self::TOKEN_ABILITY],
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plain);
    }
}
