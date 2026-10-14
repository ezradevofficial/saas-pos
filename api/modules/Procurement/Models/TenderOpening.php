<?php

namespace Modules\Procurement\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRC-10: one committee opener's confirmation of a tender's opening.
 *
 * @property string $tender_id
 * @property string $user_id
 */
class TenderOpening extends ProcurementModel
{
    use Audited;

    protected $table = 'procurement_tender_openings';

    protected string $auditModule = 'procurement';

    protected string $auditResource = 'tender_opening';

    protected function casts(): array
    {
        return ['confirmed_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
