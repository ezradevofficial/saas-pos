<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** HR-28: an onboarding or offboarding checklist template of a company. */
class ChecklistTemplate extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'hr_checklist_templates';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'checklist_template';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'is_default' => 'boolean'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
