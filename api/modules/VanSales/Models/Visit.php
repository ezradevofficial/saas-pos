<?php

namespace Modules\VanSales\Models;

use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** VAN-09: a stop visit. Positions are personal data (VAN-24): never listed or audited. */
class Visit extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    protected $table = 'vs_visits';

    protected $guarded = [];

    protected array $auditHidden = ['checkin_lat', 'checkin_lng', 'checkin_accuracy_m', 'checkin_at'];

    protected $hidden = ['checkin_lat', 'checkin_lng', 'checkin_accuracy_m', 'checkin_at'];

    protected function casts(): array
    {
        return [
            'arrived_at' => 'datetime',
            'left_at' => 'datetime',
            'checkin_at' => 'datetime',
            'device_updated_at' => 'datetime',
        ];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
