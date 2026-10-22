<?php

namespace App\Core\Marketplace\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MKT-03: the developers' payable ledger, kept in the operator tenant
 * (ADR 016). An `earning` is a developer's share of a paid app line of a
 * platform invoice; a `payout` is money staff paid out (manual, with its
 * reference). Append-only: the runtime role cannot update or delete it.
 */
class MarketplacePayable extends Model
{
    use BelongsToTenant, HasUuids;

    public const EARNING = 'earning';

    public const PAYOUT = 'payout';

    protected $table = 'marketplace_payables';

    protected $fillable = ['developer_id', 'app_id', 'kind', 'currency', 'amount_minor', 'base_minor', 'share_percent', 'operator_invoice_id', 'customer_tenant_id', 'line_no', 'reference', 'recorded_by', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'base_minor' => 'integer',
            'share_percent' => 'integer',
            'line_no' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function developer(): BelongsTo
    {
        return $this->belongsTo(MarketplaceDeveloper::class, 'developer_id');
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(MarketplaceApp::class, 'app_id');
    }
}
