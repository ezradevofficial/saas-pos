<?php

namespace App\Core\Identity\Pin;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * AUTH-08: a manager override that has been used: its id (the online
 * token's id, or the id the device gave an offline override), which
 * device, manager and cashier, the permission and the record it
 * authorised. Unique by override id, so an override authorises one thing
 * once (OverrideVerifier).
 */
class OverrideRedemption extends Model
{
    use BelongsToTenant, HasUuids;

    public const ONLINE = 'online';

    public const OFFLINE = 'offline';

    protected $fillable = ['override_id', 'mode', 'device_id', 'manager_user_id', 'cashier_user_id', 'permission', 'reference', 'authorised_at', 'redeemed_at'];

    protected function casts(): array
    {
        return ['authorised_at' => 'immutable_datetime', 'redeemed_at' => 'immutable_datetime'];
    }
}
