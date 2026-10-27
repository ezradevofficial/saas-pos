<?php

namespace Modules\FuelStation\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** FUEL-09, FUEL-10: a forecourt shift uploaded by a till. Opens and closes are audited by ShiftUploads. */
class Shift extends Model
{
    use BelongsToTenant, HasUuids;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $table = 'fuel_shifts';

    protected $guarded = ['tenant_id'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'immutable_datetime',
            'opened_received_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'closed_received_at' => 'immutable_datetime',
            'flags' => 'array',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function shiftNozzles(): HasMany
    {
        return $this->hasMany(ShiftNozzle::class, 'shift_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class, 'shift_id');
    }
}
