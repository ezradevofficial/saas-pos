<?php

namespace Modules\HR\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-23: an employee's clock-in PIN as the core PIN scheme stores one
 * (AUTH-06): an Argon2id hash, and the PBKDF2 key encrypted with the
 * application key. Never the PIN. Audited on the employee by ClockPins.
 */
class ClockPin extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'hr_clock_pins';

    protected $guarded = [];

    protected $hidden = ['pin_hash', 'pin_salt', 'pin_key'];

    protected function casts(): array
    {
        return ['pin_key' => 'encrypted', 'pin_iterations' => 'integer', 'pin_digits' => 'integer', 'version' => 'integer', 'set_at' => 'immutable_datetime'];
    }
}
