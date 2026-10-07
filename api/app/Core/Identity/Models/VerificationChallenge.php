<?php

namespace App\Core\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time code sent to a user (AUTH-01, AUTH-03, AUTH-04). Global system
 * table (no RLS, ADR 002): read before the tenant is known. Only the
 * Challenges service reads or writes it.
 */
class VerificationChallenge extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const PURPOSE_VERIFY_CONTACT = 'verify_contact';

    public const PURPOSE_TWO_FACTOR = 'two_factor';

    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    protected $guarded = [];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
