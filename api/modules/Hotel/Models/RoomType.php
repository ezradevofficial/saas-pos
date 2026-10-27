<?php

namespace Modules\Hotel\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HOT-03: a kind of room sold at a company's properties. Archived, never deleted (TEN-06).
 *
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property int $base_adults
 * @property int $max_adults
 * @property int $max_children
 * @property int $max_occupancy
 */
class RoomType extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected string $auditModule = 'hotel';

    protected string $auditResource = 'room_type';

    protected $table = 'hotel_room_types';

    protected $guarded = ['id', 'tenant_id', 'archived_at'];

    protected function casts(): array
    {
        return ['base_adults' => 'integer', 'max_adults' => 'integer', 'max_children' => 'integer', 'max_occupancy' => 'integer', 'sort' => 'integer'];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
