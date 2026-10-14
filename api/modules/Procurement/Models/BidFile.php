<?php

namespace Modules\Procurement\Models;

use App\Core\Audit\Audited;

/**
 * PRC-09: a bid attachment, its bytes encrypted with the tender's key on
 * the `media` disk (the core file layout and type whitelist, ADR 011).
 * Readable by buyers only once the tender is opened.
 *
 * @property string $tender_id
 * @property string $bidder_id
 * @property ?string $bid_id
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string $mime
 * @property int $size
 */
class BidFile extends ProcurementModel
{
    use Audited;

    protected $table = 'procurement_bid_files';

    protected string $auditModule = 'procurement';

    protected string $auditResource = 'bid_file';

    protected function casts(): array
    {
        return ['size' => 'integer', 'removed_at' => 'immutable_datetime'];
    }
}
