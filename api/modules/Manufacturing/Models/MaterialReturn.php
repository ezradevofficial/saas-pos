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
 * MFG-12: unused materials of an order back to its location, against one
 * issue, following the issue's ledger (with Inventory, at the cost the
 * issue went out at). Audited as `manufacturing.return.*`.
 *
 * @property string $id
 * @property string $number
 * @property string $order_id
 * @property string $issue_id
 * @property string $company_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $ledger
 * @property string $reason
 * @property string $returned_by
 * @property ?string $currency
 */
class MaterialReturn extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const REASONS = ['unused', 'excess', 'wrong_item', 'other'];

    protected $table = 'mfg_returns';

    protected string $auditModule = 'manufacturing';

    protected string $auditResource = 'return';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['returned_at' => 'immutable_datetime', 'valued_at' => 'immutable_datetime'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReturnLine::class, 'return_id')->orderBy('line_no');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
