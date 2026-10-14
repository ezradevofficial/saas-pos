<?php

namespace Modules\Procurement\Models;

use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRC-03: a request for quotation sent to invited suppliers; their quotes
 * are typed by the buyer (PRC-04) and compared (PRC-05).
 *
 * @property ?string $requisition_id
 * @property string $title
 * @property string $created_by
 */
class Rfq extends ProcurementDocument
{
    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::ISSUED, self::CLOSED, self::CANCELLED];

    protected $table = 'procurement_rfqs';

    protected string $auditResource = 'rfq';

    protected function casts(): array
    {
        return ['deadline_at' => 'immutable_datetime', 'issued_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RfqLine::class)->orderBy('line_no');
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(RfqSupplier::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
