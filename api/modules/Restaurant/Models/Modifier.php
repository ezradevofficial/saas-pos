<?php

namespace Modules\Restaurant\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REST-07: a modifier with a price effect in minor units. */
class Modifier extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'rest_modifiers';

    protected string $auditModule = 'restaurant';

    protected string $auditResource = 'modifier';

    protected $guarded = [];
}
