<?php

namespace Modules\Hotel\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * HOT-30: a property (one branch) and its business date, which rolls with
 * the night audit; folio lines carry it, not the calendar date.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property CarbonImmutable $business_date
 */
class Property extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'hotel_properties';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['business_date' => 'immutable_date', 'rolled_at' => 'immutable_datetime'];
    }
}
