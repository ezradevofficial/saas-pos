<?php

namespace Modules\CRM\Models;

use App\Core\MasterData\Parties\Party;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRM-26: a follow-up (call, meeting, email or task) with an optional
 * reminder (CRM-27), linked to a lead, opportunity, quote or customer.
 * A logged email keeps `{to, subject, sent}` (CRM-29).
 *
 * @property string $kind
 * @property string $subject
 * @property CarbonImmutable $starts_at
 * @property ?CarbonImmutable $ends_at
 * @property ?CarbonImmutable $remind_at
 * @property ?CarbonImmutable $reminded_at
 * @property string $status
 * @property ?string $lead_id
 * @property ?string $opportunity_id
 * @property ?string $quote_id
 * @property ?string $party_id
 * @property ?array $email
 */
class Activity extends ScopedRecord
{
    public const KINDS = ['call', 'meeting', 'email', 'task'];

    public const OPEN = 'open';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::OPEN, self::DONE, self::CANCELLED];

    protected $table = 'crm_activities';

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'remind_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime', 'done_at' => 'immutable_datetime', 'email' => 'array',
        ];
    }

    public function isOverdue(): bool
    {
        return $this->status === self::OPEN && $this->starts_at->isPast();
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
