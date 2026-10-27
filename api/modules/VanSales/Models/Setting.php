<?php

namespace Modules\VanSales\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** VAN-24, VAN-11: a company's GPS and credit settings. */
class Setting extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'vs_settings';

    protected string $auditModule = 'van_sales';

    protected string $auditResource = 'settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'gps_retention_days' => 'integer',
            'flag_offline_credit' => 'boolean',
        ];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
