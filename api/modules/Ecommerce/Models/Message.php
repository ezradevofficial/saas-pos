<?php

namespace Modules\Ecommerce\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ECOM-24: a WhatsApp message in or out. */
class Message extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected $table = 'ecom_messages';

    protected function casts(): array
    {
        return ['at' => 'immutable_datetime'];
    }
}
