<?php

namespace Modules\Restaurant\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** REST-06: a line of an order, append-only by id; sent and void marks only move forward. */
class OrderLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'rest_order_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['modifiers' => 'array', 'pending_void' => 'array', 'flags' => 'array', 'qty' => 'string', 'tax_inclusive' => 'boolean', 'void_after_send' => 'boolean', 'created_at_device' => 'datetime', 'sent_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /** The server's own record of a sent line: marked sent, or already on a ticket (a till's missing `sent_at` never makes it free). */
    public function isSent(): bool
    {
        return $this->sent_at !== null || TicketLine::query()->where('order_line_id', $this->id)->exists();
    }
}
