<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** HR-16: a shift of a company (times may cross midnight). Archived, never deleted. */
class Shift extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'hr_shifts';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'shift';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['break_minutes' => 'integer'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
