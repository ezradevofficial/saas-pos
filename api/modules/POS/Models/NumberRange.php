<?php

namespace Modules\POS\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Device;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NUM-02: a block of a document type's counter given to one device. The
 * device numbers its receipts from it offline; `next_value` is the first
 * number not known to be used. Ranges of one counter never overlap (an
 * exclusion constraint backs the counter's row lock).
 */
class NumberRange extends Model
{
    use BelongsToTenant, HasUuids;

    public const ACTIVE = 'active';

    public const EXHAUSTED = 'exhausted';

    public const RETIRED = 'retired';

    protected $table = 'pos_number_ranges';

    protected $fillable = ['device_id', 'document_type', 'number_sequence_id', 'period', 'pattern', 'range_from', 'range_to', 'next_value', 'status', 'allocated_at', 'retired_at'];

    protected function casts(): array
    {
        return [
            'range_from' => 'integer',
            'range_to' => 'integer',
            'next_value' => 'integer',
            'allocated_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function contains(int $value): bool
    {
        return $value >= $this->range_from && $value <= $this->range_to;
    }

    public function remaining(): int
    {
        return max(0, $this->range_to - $this->next_value + 1);
    }
}
