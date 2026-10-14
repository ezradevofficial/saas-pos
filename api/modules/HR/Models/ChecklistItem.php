<?php

namespace Modules\HR\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** HR-28: one item of a checklist, done or open. */
class ChecklistItem extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'hr_checklist_items';

    protected string $auditModule = 'hr';

    protected string $auditResource = 'checklist_item';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_on' => 'immutable_date', 'done_at' => 'immutable_datetime', 'position' => 'integer'];
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }
}
