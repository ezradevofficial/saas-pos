<?php

namespace Modules\Loyalty\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** LOY-08, LOY-12: one version of a promotion as it took effect (append-only). */
class PromotionVersion extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'loyalty_promotion_versions';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'version' => 'integer', 'effective_at' => 'immutable_datetime'];
    }
}
