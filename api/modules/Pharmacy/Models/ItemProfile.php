<?php

namespace Modules\Pharmacy\Models;

use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PHA-03, PHA-20, PHA-21: an item's pharmacy profile.
 *
 * @property string $item_id
 * @property string $class
 * @property string $product_kind
 * @property bool $batch_tracked
 * @property bool $restricted_sale
 * @property ?string $usage_notes
 * @property ?string $schedule_note
 * @property ?int $withdrawal_days
 */
class ItemProfile extends Model
{
    use BelongsToTenant, HasUuids;

    public const OTC = 'otc';

    public const PRESCRIPTION_ONLY = 'prescription_only';

    public const CONTROLLED = 'controlled';

    public const POISON = 'poison';

    public const AGROCHEMICAL = 'agrochemical';

    public const CLASSES = [self::OTC, self::PRESCRIPTION_ONLY, self::CONTROLLED, self::POISON, self::AGROCHEMICAL];

    public const KINDS = ['human', 'veterinary', 'agrochemical', 'other'];

    protected $table = 'pharmacy_item_profiles';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['batch_tracked' => 'boolean', 'restricted_sale' => 'boolean', 'withdrawal_days' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function isControlled(): bool
    {
        return $this->class === self::CONTROLLED;
    }

    /** PHA-16: whether a sale of this item needs a prescription under $settings. */
    public function needsPrescription(Settings $settings): bool
    {
        return in_array($this->class, $settings->prescription_classes, true);
    }

    /** PHA-17, PHA-20: whether a sale of this item needs a pharmacist's approval under $settings. */
    public function needsPharmacist(Settings $settings): bool
    {
        return $this->restricted_sale || in_array($this->class, $settings->pharmacist_classes, true);
    }
}
