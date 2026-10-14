<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-29: a disciplinary case. Sensitive (HR-09): its texts are never
 * written to the audit log, only which of them changed (HR-11).
 */
class DisciplinaryCase extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const CATEGORIES = ['verbal_warning', 'written_warning', 'final_warning', 'suspension', 'dismissal', 'other'];

    protected $table = 'hr_disciplinary_cases';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'disciplinary_case';

    protected array $auditHidden = ['description', 'action_taken', 'outcome'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['incident_date' => 'immutable_date', 'closed_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
