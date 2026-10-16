<?php

namespace Modules\Manufacturing\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MFG-12: materials taken from the order's location for the order,
 * following its ledger; valued once by Inventory (`valued_at`, MFG-15).
 * Audited as `manufacturing.issue.*`.
 *
 * @property string $id
 * @property string $number
 * @property string $order_id
 * @property string $company_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $ledger
 * @property string $issued_by
 * @property ?string $currency
 */
class Issue extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'mfg_issues';

    protected string $auditModule = 'manufacturing';

    protected string $auditResource = 'issue';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime', 'valued_at' => 'immutable_datetime'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(IssueLine::class)->orderBy('line_no');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
