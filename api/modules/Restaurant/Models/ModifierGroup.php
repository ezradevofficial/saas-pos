<?php

namespace Modules\Restaurant\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** REST-07: a group of modifiers with min and max choices, priced in one currency. */
class ModifierGroup extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'rest_modifier_groups';

    protected string $auditModule = 'restaurant';

    protected string $auditResource = 'modifier_group';

    protected $guarded = [];

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class, 'group_id')->orderBy('sort');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ModifierLink::class, 'group_id');
    }
}
