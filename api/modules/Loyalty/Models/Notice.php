<?php

namespace Modules\Loyalty\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** LOY-06, LOY-19, LOY-24: a notice sent once per kind, subject and key (never repeated). */
class Notice extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $table = 'loyalty_notices';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }
}
