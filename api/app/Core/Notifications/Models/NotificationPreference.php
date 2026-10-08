<?php

namespace App\Core\Notifications\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * NOT-04, NOT-05: one user's choices for one event type: the channels
 * they switched on or off (others follow the event's defaults) and email
 * immediately or in a daily or weekly digest.
 */
class NotificationPreference extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $fillable = ['user_id', 'event_type', 'channels', 'digest'];

    protected $attributes = ['channels' => '{}', 'digest' => 'immediate'];

    protected function casts(): array
    {
        return ['channels' => 'array'];
    }
}
