<?php

namespace Modules\Payroll\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Stage 7C closing review (decision 32): how far Payroll's copy of a
 * company has read one of HR's read contracts (`employees`, `contracts`,
 * `leave`, `attendance`): the latest `updated_at` it copied.
 *
 * @property string $company_id
 * @property string $source
 * @property CarbonImmutable $cursor
 */
class SyncCursor extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'payroll_sync_cursors';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cursor' => 'immutable_datetime'];
    }
}
