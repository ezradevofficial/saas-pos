<?php

namespace Modules\Loyalty\Models;

use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LOY-08: a promotion's current definition. Every change raises
 * `version` and stores the version (PromotionVersion, append-only), so a
 * sale is re-evaluated with what applied when it was sold (LOY-12).
 * Written and audited by the Promotions service.
 */
class Promotion extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const ARCHIVED = 'archived';

    public const STATUSES = [self::DRAFT, self::ACTIVE, self::ARCHIVED];

    protected $table = 'loyalty_promotions';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'requires_coupon' => 'boolean',
            'priority' => 'integer',
            'version' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class)->orderBy('code');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PromotionVersion::class)->orderBy('version');
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
