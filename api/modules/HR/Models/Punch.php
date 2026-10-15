<?php

namespace Modules\HR\Models;

use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-22: a clock in or out (append-only; voided once, never changed). Its
 * id comes from the device that recorded it, so uploads are idempotent.
 * Manual punches and voids are audited by Punches.
 *
 * @property string $employee_id
 * @property string $kind
 * @property CarbonImmutable $occurred_at
 * @property ?CarbonImmutable $voided_at
 * @property list<string> $flags
 */
class Punch extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const IN = 'in';

    public const OUT = 'out';

    public const SOURCES = ['web', 'till', 'device', 'manual'];

    public $timestamps = false;

    protected $table = 'hr_punches';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'voided_at' => 'immutable_datetime', 'flags' => 'array', 'work_date' => 'immutable_date'];
    }

    public function scope(): Scope
    {
        $employee = $this->employee;

        return $employee->scope();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
