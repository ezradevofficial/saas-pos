<?php

namespace Modules\Procurement\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRC-06: a committee member of a tender, `opener` (PRC-10) or `evaluator`
 * (PRC-11). Audited (PRC-24).
 *
 * @property string $tender_id
 * @property string $user_id
 * @property string $role
 */
class TenderMember extends ProcurementModel
{
    use Audited;

    public const OPENER = 'opener';

    public const EVALUATOR = 'evaluator';

    protected $table = 'procurement_tender_members';

    protected string $auditModule = 'procurement';

    protected string $auditResource = 'tender_member';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
