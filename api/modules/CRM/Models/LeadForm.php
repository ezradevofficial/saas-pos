<?php

namespace Modules\CRM\Models;

use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRM-06: a public web form that captures leads. Its token is stored only
 * as its sha256 (`token_hash`, never shown again, never exported).
 *
 * @property string $company_id
 * @property string $branch_id
 * @property string $name
 * @property ?string $source_id
 * @property ?string $team_id
 * @property array{shown: list<string>, required: list<string>} $fields
 * @property int $submissions
 */
class LeadForm extends CrmModel
{
    use Archivable;

    /** The lead fields a form may show (and require). */
    public const FIELDS = ['name', 'organisation', 'title', 'email', 'phone', 'notes'];

    protected $table = 'crm_lead_forms';

    protected string $auditResource = 'lead_form';

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'submissions' => 'integer', 'last_submitted_at' => 'immutable_datetime', 'token_rotated_at' => 'immutable_datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Option::class, 'source_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
