<?php

namespace Modules\Ecommerce\Models;

use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ECOM-08: an address a signed-in customer delivered to. */
class Address extends Model
{
    use Archivable, BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected $table = 'ecom_addresses';

    protected function casts(): array
    {
        return [];
    }
}
