<?php

namespace Modules\Procurement\Models;

/**
 * PRC-02: one need of a requisition (an item or a description).
 *
 * @property string $requisition_id
 * @property ?string $item_id
 * @property string $description
 * @property ?string $uom_id
 * @property string $qty
 * @property ?int $estimated_unit_price_minor
 * @property ?string $cost_centre_id
 * @property ?string $project_id
 */
class RequisitionLine extends ProcurementModel
{
    use HasItemLine;

    protected $table = 'procurement_requisition_lines';

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'estimated_unit_price_minor' => 'integer', 'needed_by' => 'immutable_date'];
    }
}
