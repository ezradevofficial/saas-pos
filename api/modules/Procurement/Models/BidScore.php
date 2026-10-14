<?php

namespace Modules\Procurement\Models;

use App\Core\Audit\Audited;

/**
 * PRC-11: an evaluator's score of an opened bid on one criterion.
 *
 * @property string $bid_id
 * @property string $criterion_id
 * @property string $evaluator_id
 * @property string $score
 * @property ?string $comment
 */
class BidScore extends ProcurementModel
{
    use Audited;

    protected $table = 'procurement_bid_scores';

    protected string $auditModule = 'procurement';

    protected string $auditResource = 'bid_score';
}
