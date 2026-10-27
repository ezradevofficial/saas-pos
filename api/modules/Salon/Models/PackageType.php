<?php

namespace Modules\Salon\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-18: a prepaid bundle sold as a core service item.
 *
 * @property string $company_id
 * @property string $item_id
 * @property string $name
 * @property string $kind
 * @property int $sessions
 * @property ?int $validity_days
 * @property list<string> $service_ids
 */
class PackageType extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'salon_package_types';

    protected string $auditModule = 'salon';

    protected string $auditResource = 'package_type';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sessions' => 'integer', 'validity_days' => 'integer', 'service_ids' => 'array'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
