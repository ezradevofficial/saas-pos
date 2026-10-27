<?php

namespace Modules\Salon\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-14: a message sent about an appointment, once per kind and version.
 *
 * @property string $appointment_id
 * @property string $kind
 * @property string $dedupe
 */
class Reminder extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'salon_reminders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }
}
