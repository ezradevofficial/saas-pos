<?php

namespace App\Core\Marketplace\OAuth;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * API-02 (OAuth): a row of Passport's auth codes, access or refresh
 * tokens, in the tenant of the install (row-level security). The key is
 * sha256 of the token's identifier (OAuthTokens::hash): the identifier
 * itself is only inside the signed or encrypted value the app holds, so
 * the table alone never yields a usable token.
 */
abstract class OAuthToken extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'revoked' => 'boolean',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function isUsable(): bool
    {
        return ! $this->revoked && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
