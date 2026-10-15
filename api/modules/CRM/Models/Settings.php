<?php

namespace Modules\CRM\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRM-01: a company's CRM settings.
 *
 * @property string $company_id
 * @property ?int $quote_approval_minor
 * @property int $quote_validity_days
 * @property ?string $default_team_id
 * @property bool $lost_reason_required
 */
class Settings extends CrmModel
{
    protected $table = 'crm_settings';

    protected string $auditResource = 'settings';

    protected $attributes = ['quote_validity_days' => 30, 'lost_reason_required' => true];

    protected function casts(): array
    {
        return ['quote_approval_minor' => 'integer', 'quote_validity_days' => 'integer', 'lost_reason_required' => 'boolean'];
    }

    public function defaultTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'default_team_id');
    }
}
