<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** HR-16: a cycle of days, each a shift id or a rest day (null). Archived, never deleted. */
class Rotation extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'hr_rotations';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'rotation';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['days' => 'array', 'cycle_days' => 'integer'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
