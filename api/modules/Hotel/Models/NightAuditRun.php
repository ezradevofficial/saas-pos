<?php

namespace Modules\Hotel\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * HOT-30: a completed night audit of a property for a business date, with
 * the occupancy snapshot. Unique per branch and business date.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property CarbonImmutable $business_date
 * @property array<string, mixed> $summary
 */
class NightAuditRun extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'hotel_night_audits';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'summary' => 'array',
        ];
    }
}
