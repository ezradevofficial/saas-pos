<?php

namespace Modules\Hotel\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HOT-20, HOT-26: a guest folio (one per stay) or a master folio (one per
 * group reservation). Its balance is the sum of its lines (charges
 * positive, payments negative); a settled folio takes no more lines.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $reservation_id
 * @property ?string $stay_id
 * @property ?string $guest_id
 * @property string $kind
 * @property string $number
 * @property string $currency
 * @property string $status
 * @property ?string $invoice_number
 */
class Folio extends Model
{
    use BelongsToTenant, HasUuids;

    public const GUEST = 'guest';

    public const MASTER = 'master';

    public const OPEN = 'open';

    public const SETTLED = 'settled';

    protected $table = 'hotel_folios';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['settled_at' => 'immutable_datetime', 'balance_left_minor' => 'integer'];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(Stay::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FolioLine::class)->orderBy('line_no');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
