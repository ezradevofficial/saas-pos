<?php

namespace Modules\Inventory\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * INV-01: how a stock item is tracked: none, by batch (with expiry when
 * `track_expiry`) or by serial number. An item without a row is `none`.
 * Audited as `inventory.item_settings.*` (INV-24).
 *
 * @property string $item_id
 * @property string $tracking
 * @property bool $track_expiry
 */
class ItemSetting extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const NONE = 'none';

    public const BATCH = 'batch';

    public const SERIAL = 'serial';

    public const TRACKING = [self::NONE, self::BATCH, self::SERIAL];

    protected $table = 'inventory_item_settings';

    protected string $auditModule = 'inventory';

    protected string $auditResource = 'item_settings';

    protected $fillable = ['item_id', 'tracking', 'track_expiry'];

    protected $attributes = ['tracking' => self::NONE, 'track_expiry' => false];

    protected function casts(): array
    {
        return ['track_expiry' => 'boolean'];
    }
}
