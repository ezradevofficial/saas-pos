<?php

namespace Modules\Restaurant\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REST-13: a company's service charge and the core items that carry the charge and the tip on a sale. */
class Setting extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'rest_settings';

    protected string $auditModule = 'restaurant';

    protected string $auditResource = 'settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['service_charge_percent' => 'string', 'tip_presets' => 'array'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
