<?php

namespace Modules\CRM\Models;

use App\Core\Currency\FxSnapshot;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Archivable;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CRM-14: an opportunity for a customer, in a pipeline stage; its value
 * in minor units of its currency; won or lost with a reason, its base
 * value frozen at the close date's rate (CUR-04).
 *
 * @property string $party_id
 * @property ?string $lead_id
 * @property string $pipeline_id
 * @property string $stage_id
 * @property string $name
 * @property int $value_minor
 * @property string $currency
 * @property ?CarbonImmutable $expected_close_on
 * @property int $probability
 * @property string $status
 * @property ?string $reason_id
 * @property ?CarbonImmutable $closed_on
 * @property ?FxSnapshot $fx
 * @property ?string $base_currency
 * @property ?int $base_value_minor
 * @property ?array $custom
 */
class Opportunity extends ScopedRecord
{
    use Archivable;

    public const OPEN = 'open';

    public const WON = 'won';

    public const LOST = 'lost';

    public const STATUSES = [self::OPEN, self::WON, self::LOST];

    protected $table = 'crm_opportunities';

    protected function casts(): array
    {
        return [
            'value_minor' => 'integer', 'probability' => 'integer', 'base_value_minor' => 'integer',
            'expected_close_on' => 'immutable_date', 'closed_on' => 'immutable_date',
            'fx' => FxSnapshot::class.':fx', 'custom' => 'array',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(Option::class, 'reason_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /** value × probability ÷ 100, rounded half up, in minor units. */
    public function weightedMinor(): string
    {
        return (string) BigDecimal::of((string) $this->value_minor)->multipliedBy($this->probability)->dividedBy(100, 0, RoundingMode::HalfUp);
    }
}
