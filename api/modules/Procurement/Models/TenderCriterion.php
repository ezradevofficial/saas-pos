<?php

namespace Modules\Procurement\Models;

/**
 * PRC-06, PRC-11: a technical criterion, its weight (the tender's weights
 * sum to 100) and its maximum score.
 *
 * @property string $tender_id
 * @property string $name
 * @property int $weight
 * @property int $max_score
 */
class TenderCriterion extends ProcurementModel
{
    protected $table = 'procurement_tender_criteria';

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'weight' => 'integer', 'max_score' => 'integer'];
    }
}
