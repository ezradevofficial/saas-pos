<?php

namespace App\Core\Identity\Pin;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * AUTH-06: a user's POS PIN and optional staff card, as hashes only.
 *
 *  - `pin_hash`, `card_hash`: Argon2id, for checks on the server
 *    (Pins::verify, manager overrides).
 *  - `pin_salt`, `pin_iterations`, `pin_key` (and the card's): the
 *    PBKDF2-HMAC-SHA256 key of the PIN, encrypted with the application key.
 *    Each device's verifier is an HMAC of it under the device's secret
 *    (Pins::material); the key never leaves the server as such.
 *
 * `version` rises with every change, which clears lockouts and gives
 * devices new verifiers. Changes are audited on the user
 * (`core.user.pin_set`, `pin_reset`, `pin_clear`) without any of these
 * values. Not audited through model events.
 */
class UserPin extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'user_id', 'pin_hash', 'pin_salt', 'pin_iterations', 'pin_key',
        'card_hash', 'card_salt', 'card_iterations', 'card_key', 'version', 'pin_set_at', 'set_by', 'pin_digits', 'must_change',
    ];

    protected $hidden = ['pin_hash', 'pin_salt', 'pin_key', 'card_hash', 'card_salt', 'card_key'];

    protected function casts(): array
    {
        return [
            'pin_key' => 'encrypted',
            'card_key' => 'encrypted',
            'pin_iterations' => 'integer',
            'card_iterations' => 'integer',
            'version' => 'integer',
            'pin_digits' => 'integer',
            'must_change' => 'boolean',
            'pin_set_at' => 'datetime',
        ];
    }

    public function hasPin(): bool
    {
        return $this->pin_hash !== null;
    }

    public function hasCard(): bool
    {
        return $this->card_hash !== null;
    }
}
