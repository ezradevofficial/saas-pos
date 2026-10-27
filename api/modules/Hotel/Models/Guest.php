<?php

namespace Modules\Hotel\Models;

use App\Core\Audit\Audited;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HOT-16: a guest, a customer party with identity document fields. The ID
 * number is encrypted at rest; it, the date of birth and the address are
 * personal data (NFR-08): registered with the privacy module, hidden by
 * field rules and `hotel.guest.identity`, never audited in clear.
 *
 * @property string $id
 * @property string $company_id
 * @property string $party_id
 * @property string $name
 * @property ?string $phone
 * @property ?string $email
 * @property ?string $nationality
 * @property ?string $id_type
 * @property ?string $id_number
 * @property ?CarbonImmutable $date_of_birth
 * @property ?string $address
 */
class Guest extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const ID_TYPES = ['national_id', 'passport', 'driving_licence', 'other'];

    protected string $auditModule = 'hotel';

    protected string $auditResource = 'guest';

    protected array $auditHidden = ['id_number', 'date_of_birth', 'address', 'phone', 'email', 'notes'];

    protected $table = 'hotel_guests';

    protected $guarded = ['id', 'tenant_id', 'archived_at'];

    protected $hidden = ['id_number'];

    protected function casts(): array
    {
        return ['id_number' => 'encrypted', 'date_of_birth' => 'immutable_date'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
