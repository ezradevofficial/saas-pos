<?php

namespace Modules\Loyalty\Models;

use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LOY-03, LOY-12: Loyalty's own copy of a POS sale it handled (the id is
 * the POS sale's): the snapshot the event carried, what it earned and
 * reversed, and what the server noticed (`flags`). Kept before processing,
 * so a failure is retried by `loyalty:reprocess` without reading POS.
 */
class LoyaltySale extends Model implements HasScope
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const PROCESSED = 'processed';

    public const FAILED = 'failed';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'loyalty_sales';

    protected $guarded = ['tenant_id'];

    protected function casts(): array
    {
        return [
            'flags' => 'array',
            'snapshot' => 'array',
            'sold_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(PromotionApplication::class, 'sale_id');
    }

    public function scope(): Scope
    {
        return Scope::branch($this->branch_id);
    }
}
