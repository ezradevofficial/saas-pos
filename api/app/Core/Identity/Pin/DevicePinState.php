<?php

namespace App\Core\Identity\Pin;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * AUTH-06: wrong PIN or card attempts of one user on one device, online
 * (Pins::verify) or reported by the device after offline attempts
 * (Pins::report). Locked once `failed_attempts` reaches the limit, until
 * the PIN is changed. Lockouts are audited as `core.user.pin_locked`.
 */
class DevicePinState extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['device_id', 'user_id', 'failed_attempts', 'last_failed_at', 'locked_at'];

    protected $attributes = ['failed_attempts' => 0];

    protected function casts(): array
    {
        return [
            'failed_attempts' => 'integer',
            'last_failed_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
