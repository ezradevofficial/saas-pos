<?php

namespace Modules\Procurement\Models;

use App\Core\Audit\Audited;

/**
 * PRC-01: a company's three-way match tolerances and over-receipt
 * allowance, in percent (audited, AUD-01).
 *
 * @property string $company_id
 * @property string $price_tolerance_percent
 * @property string $qty_tolerance_percent
 * @property string $over_receipt_percent
 */
class Settings extends ProcurementModel
{
    use Audited;

    protected $table = 'procurement_settings';

    protected string $auditModule = 'procurement';

    protected string $auditResource = 'settings';
}
