<?php

namespace App\Core\Identity\Pin;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * AUTH-07: a till sign-in the server checked online (POST pos/pin/verify
 * with a `session_id`): which device, which user, the session id the
 * device made for that sign-in, the time the device stated, and when the
 * server checked it. Unique per device and session (TillSignIns).
 */
class TillSignIn extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['device_id', 'user_id', 'session_id', 'signed_in_at', 'verified_at'];

    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime'];
    }
}
