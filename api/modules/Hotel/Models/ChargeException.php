<?php

namespace Modules\Hotel\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * HOT-22: a room charge from a till that could not be posted (the guest has
 * left, the room is unknown, the folio is settled). Kept for the front desk
 * to move to a folio or bill another way (device wins, NFR-04).
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $source
 * @property string $source_id
 * @property ?string $reference
 * @property ?string $room_number
 * @property string $currency
 * @property int $amount_minor
 * @property string $reason
 * @property string $status
 */
class ChargeException extends Model
{
    use BelongsToTenant, HasUuids;

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    public const RESOLUTIONS = ['moved', 'billed_other', 'written_off'];

    protected $table = 'hotel_charge_exceptions';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'resolved_at' => 'immutable_datetime'];
    }
}
