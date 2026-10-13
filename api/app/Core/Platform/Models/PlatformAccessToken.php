<?php

namespace App\Core\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A platform staff bearer token (ADR 013). Kept apart from tenant tokens
 * (personal_access_tokens): the `platform` guard reads only this table and
 * Sanctum only that one, so neither kind of token works on the other API.
 *
 * @property string $id
 * @property string $platform_user_id
 * @property string $token
 * @property list<string> $abilities
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 */
class PlatformAccessToken extends Model
{
    use HasUuids;

    /** Every console action, given only once two-factor is set up. */
    public const FULL = '*';

    /** Only enrol two-factor, read oneself and sign out. */
    public const ENROL = 'platform:enrol';

    protected $table = 'platform_access_tokens';

    protected $fillable = ['platform_user_id', 'token', 'abilities', 'ip', 'user_agent', 'expires_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'platform_user_id');
    }

    public function isFull(): bool
    {
        return in_array(self::FULL, $this->abilities ?? [], true);
    }
}
