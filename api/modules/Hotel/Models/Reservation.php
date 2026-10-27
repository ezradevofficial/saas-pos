<?php

namespace Modules\Hotel\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HOT-12: a booking (direct, walk-in or group) holding one or more stays.
 * Created and changed only through Reservations (which audits).
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $number
 * @property string $kind
 * @property string $guest_id
 * @property ?string $group_name
 * @property string $source
 * @property ?string $notes
 */
class Reservation extends Model
{
    use BelongsToTenant, HasUuids;

    public const DIRECT = 'direct';

    public const WALK_IN = 'walk_in';

    public const GROUP = 'group';

    public const KINDS = [self::DIRECT, self::WALK_IN, self::GROUP];

    public const SOURCES = ['direct', 'phone', 'walk_in', 'online', 'agent', 'other'];

    protected $table = 'hotel_reservations';

    protected $guarded = ['id', 'tenant_id'];

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class)->orderBy('arrival_on')->orderBy('created_at');
    }

    public function folios(): HasMany
    {
        return $this->hasMany(Folio::class);
    }
}
