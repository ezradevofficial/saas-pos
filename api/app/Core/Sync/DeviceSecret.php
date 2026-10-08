<?php

namespace App\Core\Sync;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * AUTH-06, AUTH-08: one secret of a device, by key id (`kid`): `pending`
 * after a rotation until the device proves it holds it, `current`, or
 * `retired` (kept so overrides signed while it was current still verify).
 * The secret is encrypted with the application key and never serialised.
 * Changes are audited through DeviceSecrets on the device.
 */
class DeviceSecret extends Model
{
    use BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const CURRENT = 'current';

    public const RETIRED = 'retired';

    protected $fillable = ['device_id', 'kid', 'secret', 'status', 'issued_at', 'activated_at', 'retired_at'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'issued_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    /** The raw 32 bytes. */
    public function raw(): string
    {
        return DeviceSecrets::decode($this->secret);
    }
}
