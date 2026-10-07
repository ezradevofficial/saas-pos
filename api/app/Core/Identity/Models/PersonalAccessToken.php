<?php

namespace App\Core\Identity\Models;

use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * A device session (AUTH-09). The table is global (no RLS, ADR 002): a token
 * is found before the tenant is known, then sets the tenant context from its
 * own tenant_id (TEN-01). Callers must always scope queries by tokenable.
 */
class PersonalAccessToken extends SanctumToken
{
    use HasUuids;

    protected $table = 'personal_access_tokens';

    protected $fillable = ['tenant_id', 'name', 'token', 'abilities', 'expires_at', 'ip', 'user_agent'];

    /**
     * Only `id|secret` tokens are accepted; the id must be a UUID so a
     * malformed token never reaches the database as a bad cast.
     */
    public static function findToken($token)
    {
        if (! is_string($token) || ! str_contains($token, '|')) {
            return null;
        }

        [$id, $secret] = explode('|', $token, 2);

        if ($secret === '' || ! Str::isUuid($id)) {
            return null;
        }

        $instance = static::find($id);

        if ($instance === null || ! hash_equals($instance->token, hash('sha256', $secret))) {
            return null;
        }

        app(TenantContext::class)->set($instance->tenant_id);

        return $instance;
    }
}
