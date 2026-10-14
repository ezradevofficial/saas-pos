<?php

namespace Modules\Procurement\Models;

use App\Core\Currency\FxSnapshot;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRC-15: a supplier invoice against a PO, three-way matched against the
 * PO and its posted GRNs; approved, it raises SupplierInvoiceApproved.
 *
 * @property string $supplier_reference
 * @property string $party_id
 * @property string $order_id
 * @property CarbonImmutable $invoice_date
 * @property ?CarbonImmutable $due_date
 * @property string $currency
 * @property ?FxSnapshot $fx
 * @property ?string $base_currency
 * @property bool $tax_inclusive
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property ?string $override_reason
 * @property string $created_by
 */
class Invoice extends ProcurementDocument
{
    public const DRAFT = 'draft';

    public const MATCHED = 'matched';

    public const EXCEPTION = 'exception';

    public const APPROVED = 'approved';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::DRAFT, self::MATCHED, self::EXCEPTION, self::APPROVED, self::CANCELLED];

    protected $table = 'procurement_invoices';

    protected string $auditResource = 'invoice';

    protected function casts(): array
    {
        return [
            'fx' => FxSnapshot::class.':fx', 'tax_inclusive' => 'boolean', 'invoice_date' => 'immutable_date', 'due_date' => 'immutable_date',
            'approved_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
            'subtotal_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer',
            'base_subtotal_minor' => 'integer', 'base_tax_minor' => 'integer', 'base_total_minor' => 'integer', 'base_grni_minor' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_no');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
