<?php

namespace Modules\CRM\Models;

use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Archivable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRM-05: a lead at a branch, owned by a user (or not yet assigned).
 *
 * @property string $status
 * @property string $name
 * @property ?string $organisation
 * @property ?string $email
 * @property ?string $phone
 * @property ?string $source_id
 * @property string $channel
 * @property ?int $estimated_value_minor
 * @property ?string $currency
 * @property ?string $party_id
 * @property ?string $opportunity_id
 * @property ?array $custom
 */
class Lead extends ScopedRecord
{
    use Archivable;

    public const NEW = 'new';

    public const CONTACTED = 'contacted';

    public const QUALIFIED = 'qualified';

    public const CONVERTED = 'converted';

    public const DISQUALIFIED = 'disqualified';

    public const STATUSES = [self::NEW, self::CONTACTED, self::QUALIFIED, self::CONVERTED, self::DISQUALIFIED];

    /** CRM-13: the statuses a person sets by hand (kanban columns). */
    public const OPEN = [self::NEW, self::CONTACTED, self::QUALIFIED];

    public const CHANNELS = ['manual', 'form', 'import', 'api'];

    protected $table = 'crm_leads';

    protected function casts(): array
    {
        return ['estimated_value_minor' => 'integer', 'custom' => 'array', 'converted_at' => 'immutable_datetime'];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true) && ! $this->isArchived();
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Option::class, 'source_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(LeadForm::class, 'form_id');
    }
}
