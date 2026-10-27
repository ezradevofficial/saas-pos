<?php

namespace Modules\Salon\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-16 to SAL-18: credit or a sale the module could not honour or match; the sale stands (device wins) and a person
 * looks at it.
 *
 * @property string $company_id
 * @property ?string $branch_id
 * @property string $kind
 * @property string $source_key
 * @property ?string $appointment_id
 * @property ?string $sale_id
 * @property ?string $package_id
 * @property ?int $amount_minor
 * @property ?string $currency
 * @property array<string, mixed> $detail
 * @property string $status
 */
class SalonException extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'salon_exceptions';

    protected string $auditModule = 'salon';

    protected string $auditResource = 'exception';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['detail' => 'array', 'amount_minor' => 'integer', 'resolved_at' => 'immutable_datetime'];
    }

    public function scope(): Scope
    {
        return $this->branch_id !== null ? Scope::branch($this->branch_id) : Scope::company($this->company_id);
    }
}
