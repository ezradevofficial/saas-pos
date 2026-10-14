<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** HR-30: one employee's appraisal in a cycle. */
class Appraisal extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const STATUSES = ['self_review', 'manager_review', 'completed', 'acknowledged'];

    protected $table = 'hr_appraisals';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'appraisal';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['goals' => 'array', 'self_rating' => 'integer', 'manager_rating' => 'integer', 'completed_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(AppraisalCycle::class, 'cycle_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
