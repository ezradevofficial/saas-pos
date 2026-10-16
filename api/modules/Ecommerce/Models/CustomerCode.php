<?php

namespace Modules\Ecommerce\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ECOM-08: a sign-in code sent to a contact (kept only as an HMAC). */
class CustomerCode extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected $table = 'ecom_customer_codes';

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }
}
