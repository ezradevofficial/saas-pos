<?php

namespace Modules\Inventory\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * INV-12, INV-13: a company's costing method (fixed once it has a movement)
 * and whether manual issues may take stock below zero. Audited as
 * `inventory.settings.*` (INV-24).
 *
 * @property string $company_id
 * @property string $costing_method
 * @property bool $allow_negative
 * @property ?CarbonImmutable $costing_locked_at
 */
class InventorySetting extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const FIFO = 'fifo';

    public const WEIGHTED_AVERAGE = 'weighted_average';

    public const METHODS = [self::FIFO, self::WEIGHTED_AVERAGE];

    protected $table = 'inventory_settings';

    protected string $auditModule = 'inventory';

    protected string $auditResource = 'settings';

    protected $fillable = ['company_id', 'costing_method', 'allow_negative', 'costing_locked_at'];

    protected $attributes = ['costing_method' => self::WEIGHTED_AVERAGE, 'allow_negative' => true];

    protected function casts(): array
    {
        return ['allow_negative' => 'boolean', 'costing_locked_at' => 'immutable_datetime'];
    }
}
