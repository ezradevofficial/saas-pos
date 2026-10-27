<?php

namespace Modules\Salon\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-12: a walk-in waiting at a branch.
 *
 * @property string $company_id
 * @property string $branch_id
 * @property ?string $device_id
 * @property ?string $client_id
 * @property string $guest_name
 * @property ?string $guest_phone
 * @property list<string> $service_ids
 * @property ?string $staff_id
 * @property string $status
 * @property ?string $appointment_id
 * @property CarbonImmutable $joined_at
 */
class QueueEntry extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const WAITING = 'waiting';

    public const SEATED = 'seated';

    public const LEFT = 'left';

    protected $table = 'salon_queue';

    protected string $auditModule = 'salon';

    protected string $auditResource = 'queue_entry';

    protected array $auditHidden = ['guest_name', 'guest_phone'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['service_ids' => 'array', 'joined_at' => 'immutable_datetime', 'seated_at' => 'immutable_datetime', 'left_at' => 'immutable_datetime', 'anonymised_at' => 'immutable_datetime'];
    }

    public function scope(): Scope
    {
        return Scope::branch($this->branch_id);
    }
}
