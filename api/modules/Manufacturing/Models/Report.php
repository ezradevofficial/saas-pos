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
 * MFG-13, MFG-16: production reported against an order: good, scrap and
 * by-product quantities and the labour minutes. Status: `recorded`
 * (quantity-only order), `pending` (waits for its order's materials to be
 * valued), `costed` (its cost is known and sent to Inventory), `received`
 * (Inventory received it). Audited as `manufacturing.report.*`.
 *
 * @property string $id
 * @property string $number
 * @property string $order_id
 * @property string $company_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $ledger
 * @property string $status
 * @property string $qty_good
 * @property string $qty_good_base
 * @property string $qty_scrap
 * @property string $qty_scrap_base
 * @property ?string $scrap_reason
 * @property int $labour_minutes
 * @property int|string|null $material_cost_minor
 * @property int|string|null $labour_cost_minor
 * @property int|string|null $overhead_cost_minor
 * @property int|string|null $cost_minor
 * @property string $reported_by
 * @property ?string $currency
 */
class Report extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const RECORDED = 'recorded';

    public const PENDING = 'pending';

    public const COSTED = 'costed';

    public const RECEIVED = 'received';

    public const SCRAP_REASONS = ['damaged', 'quality', 'process', 'other'];

    protected $table = 'mfg_reports';

    protected string $auditModule = 'manufacturing';

    protected string $auditResource = 'report';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['labour_minutes' => 'integer', 'reported_at' => 'immutable_datetime', 'costed_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
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
        return $this->hasMany(ReportLine::class)->orderBy('line_no');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
