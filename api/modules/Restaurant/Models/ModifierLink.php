<?php

namespace Modules\Restaurant\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** REST-07: what a modifier group attaches to (an item or an item category). */
class ModifierLink extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'rest_modifier_links';

    protected $guarded = [];
}
