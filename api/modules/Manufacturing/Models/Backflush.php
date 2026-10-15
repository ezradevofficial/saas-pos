<?php

namespace Modules\Manufacturing\Models;

use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MFG-07, MFG-08: what one POS sale consumed through its menu items'
 * recipes (`consume`, source `pos.sale`), or what its void or a refund
 * gave back (`return`, sources `pos.void`, `pos.refund`); one per source,
 * so an event delivered twice consumes once. Never changed but for its
 * value, recorded once (`valued_at`).
 *
 * @property string $id
 * @property string $source_type
 * @property string $source_id
 * @property string $sale_id
 * @property string $kind
 * @property string $company_id
 * @property string $branch_id
 * @property string $location_id
 * @property ?string $reference
 * @property list<array{sale_line_id: string, item_id: string, code: string}>|null $skipped
 * @property ?string $currency
 */
class Backflush extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const CONSUME = 'consume';

    public const RETURN = 'return';

    public const SALE = 'pos.sale';

    public const VOID = 'pos.void';

    public const REFUND = 'pos.refund';

    protected $table = 'mfg_backflushes';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['skipped' => 'array', 'occurred_at' => 'immutable_datetime', 'valued_at' => 'immutable_datetime'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BackflushLine::class)->orderBy('line_no');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
