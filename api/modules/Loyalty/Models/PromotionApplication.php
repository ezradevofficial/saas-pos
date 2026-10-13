<?php

namespace Modules\Loyalty\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** LOY-12: what the till applied for a promotion on a sale, what the server expected, and the flags. */
class PromotionApplication extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'loyalty_promotion_applications';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['flags' => 'array', 'version' => 'integer'];
    }
}
