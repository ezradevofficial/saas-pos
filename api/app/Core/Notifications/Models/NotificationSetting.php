<?php

namespace App\Core\Notifications\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * NOT-04: the channels of one event type a tenant makes mandatory: users
 * cannot switch them off. No row, or an empty list, means none.
 */
class NotificationSetting extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $fillable = ['event_type', 'mandatory_channels', 'updated_by'];

    protected $attributes = ['mandatory_channels' => '[]'];

    protected function casts(): array
    {
        return ['mandatory_channels' => 'array'];
    }
}
