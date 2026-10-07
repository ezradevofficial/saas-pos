<?php

namespace App\Core\Identity\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation to join a tenant (AUTH-05) by email or phone, carrying the
 * role assignments the invitee gets on accepting. Valid for 7 days; the
 * token is sent once and stored as a sha256 hash. Audited by its service as
 * `core.user.invite`, `core.user.invitation_revoke` and
 * `core.user.invitation_accept`.
 */
class Invitation extends Model
{
    use BelongsToTenant, HasUuids;

    public const VALID_DAYS = 7;

    public const TOKEN_LENGTH = 40;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = ['tenant_id', 'email', 'phone', 'name', 'assignments', 'token_hash', 'expires_at', 'invited_by'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'assignments' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => self::STATUS_ACCEPTED,
            $this->revoked_at !== null => self::STATUS_REVOKED,
            $this->expires_at->isPast() => self::STATUS_EXPIRED,
            default => self::STATUS_PENDING,
        };
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
