<?php

namespace App\Core\Workflow\Calendar;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's working hours per weekday (WF-09, APR-05), in its time
 * zone: {"mon": [["08:00", "17:00"]], ..., "sun": []}. Audited as
 * `core.business_hours.*`.
 *
 * @property string $company_id
 * @property array<string, list<array{0: string, 1: string}>> $hours
 */
class BusinessHours extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected string $auditResource = 'business_hours';

    protected $table = 'business_hours';

    protected $fillable = ['company_id', 'hours'];

    protected function casts(): array
    {
        return ['hours' => 'array'];
    }
}
