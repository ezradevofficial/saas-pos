<?php

namespace App\Core\MasterData\Prices;

use App\Core\Audit\Auditor;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MD-03 follow-up: an item's price in a price list, for one unit (the
 * item's base unit or one of its other units), from `effective_from` (a
 * date in the company's time zone) and from `min_quantity` of that unit (a
 * quantity break; 1 by default). `amount_minor` is in minor units of the
 * list's currency, which `currency` repeats (the database refuses any
 * other, ADR 003). Archived, never deleted (TEN-06).
 *
 * Audited as `core.item_price.{create,update,archive,restore}` (AUD-01).
 * Every entry carries the whole price (list, item, unit, start, break,
 * amount and currency) before and after, so the history of the item and of
 * the price list (MD-07) can say which price changed.
 */
class ItemPrice extends Model
{
    use Archivable, BelongsToTenant, HasUuids;

    /** Values every audit entry carries (AUD-01). */
    public const AUDITED = ['price_list_id', 'item_id', 'uom_id', 'effective_from', 'min_quantity', 'amount_minor', 'currency'];

    protected $fillable = ['price_list_id', 'item_id', 'uom_id', 'amount_minor', 'currency', 'effective_from', 'min_quantity'];

    protected $attributes = ['min_quantity' => '1'];

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d'];
    }

    protected static function booted(): void
    {
        static::created(fn (self $price) => $price->audit('create', null, $price->snapshot(false)));

        static::updated(function (self $price) {
            $changes = array_intersect(array_keys($price->getChanges()), [...self::AUDITED, 'archived_at']);

            if ($changes === []) {
                return;
            }

            $verb = match (true) {
                ! in_array('archived_at', $changes, true) => 'update',
                $price->getRawOriginal('archived_at') === null => 'archive',
                default => 'restore',
            };

            $price->audit($verb, $price->snapshot(true), $price->snapshot(false));
        });
    }

    /** The change and its audit entry share one transaction (AUD-01). */
    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn () => parent::save($options));
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    /** "1.000000" => "1", "2.500000" => "2.5". */
    public function minQuantity(): string
    {
        return self::quantity((string) $this->min_quantity);
    }

    public static function quantity(string $value): string
    {
        return (string) BigDecimal::of($value)->strippedOfTrailingZeros();
    }

    /** @return array<string, mixed> */
    private function snapshot(bool $original): array
    {
        $value = fn (string $key) => $original ? $this->getRawOriginal($key) : $this->getAttributes()[$key] ?? null;
        $date = $value('effective_from');

        return [
            'price_list_id' => $value('price_list_id'),
            'item_id' => $value('item_id'),
            'uom_id' => $value('uom_id'),
            'effective_from' => $date === null ? null : substr((string) $date, 0, 10),
            'min_quantity' => self::quantity((string) ($value('min_quantity') ?? '1')),
            // A string: amounts may pass 2^53 (ADR 003).
            'amount_minor' => (string) $value('amount_minor'),
            'currency' => $value('currency'),
        ];
    }

    private function audit(string $verb, ?array $before, ?array $after): void
    {
        app(Auditor::class)->record("core.item_price.{$verb}", $this, $before, $after);
    }
}
