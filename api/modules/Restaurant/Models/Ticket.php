<?php

namespace Modules\Restaurant\Models;

use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** REST-09: a ticket for a station and round (kind order, resend or void). */
class Ticket extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    protected $table = 'rest_tickets';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['preparing_at' => 'datetime', 'ready_at' => 'datetime', 'served_at' => 'datetime', 'business_date' => 'date:Y-m-d', 'offline' => 'boolean'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TicketLine::class, 'ticket_id')->orderBy('created_at')->orderBy('id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_id');
    }
}
