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
 * SAL-18: a package of N sessions a customer bought. Its value is the price paid; a session is worth the remaining value
 * over the remaining sessions (the last takes the rest), so the liability is exact.
 *
 * @property string $company_id
 * @property string $type_id
 * @property string $customer_id
 * @property string $name
 * @property string $sale_id
 * @property string $sale_line_id
 * @property int $sessions_total
 * @property int $sessions_used
 * @property int $price_minor
 * @property int $remaining_minor
 * @property string $currency
 * @property list<string> $service_ids
 * @property string $status
 * @property ?CarbonImmutable $expires_on
 */
class Package extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const ACTIVE = 'active';

    public const EXHAUSTED = 'exhausted';

    public const EXPIRED = 'expired';

    public const VOID = 'void';

    protected $table = 'salon_packages';

    protected string $auditModule = 'salon';

    protected string $auditResource = 'package';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sessions_total' => 'integer', 'sessions_used' => 'integer', 'price_minor' => 'integer', 'remaining_minor' => 'integer',
            'service_ids' => 'array', 'expires_on' => 'immutable_date',
        ];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }

    public function sessionsLeft(): int
    {
        return $this->sessions_total - $this->sessions_used;
    }

    /** What the next session is worth: the remaining value over the remaining sessions, the last one taking the rest. */
    public function sessionValue(): int
    {
        $left = $this->sessionsLeft();

        return $left <= 1 ? $this->remaining_minor : intdiv($this->remaining_minor, $left);
    }
}
