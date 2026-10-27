<?php

namespace Modules\Restaurant\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REST-10: the device's id of a re-send, so a repeat makes no second ticket. */
class Resend extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'rest_resends';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['resent_at' => 'datetime'];
    }
}
