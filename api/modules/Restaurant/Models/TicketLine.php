<?php

namespace Modules\Restaurant\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REST-09: what a ticket asks the station to make. */
class TicketLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'rest_ticket_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['modifiers' => 'array', 'qty' => 'string', 'void' => 'boolean'];
    }
}
