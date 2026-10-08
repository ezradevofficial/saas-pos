<?php

namespace App\Core\Approvals\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * APR-08: an approve or reject link from an email, bound to one
 * assignment and its user; the token itself is never stored (sha256).
 *
 * @property string $id
 * @property string $assignment_id
 * @property string $user_id
 * @property string $action
 * @property ?\Carbon\CarbonImmutable $expires_at
 * @property ?\Carbon\CarbonImmutable $used_at
 */
class ApprovalEmailToken extends Model
{
    use BelongsToTenant, HasUuids;

    public const TOKEN_LENGTH = 48;

    protected $fillable = ['assignment_id', 'user_id', 'action', 'token_hash', 'expires_at', 'used_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
