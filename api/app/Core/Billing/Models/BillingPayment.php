<?php

namespace App\Core\Billing\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SUB-04: the tenant's view of one payment of its invoice. Its id is the
 * operator's payment intent id; the billing service keeps the status in
 * step (pending, unknown, succeeded, failed, cancelled, timeout).
 * `redirect_url` is the hosted card checkout to open, when there is one.
 *
 * @property string $id
 * @property string $billing_invoice_id
 * @property string|null $payment_method_id
 * @property string $provider
 * @property string $label
 * @property string $currency
 * @property int $amount_minor
 * @property string $status
 * @property string|null $redirect_url
 * @property string|null $message
 */
class BillingPayment extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $fillable = ['id', 'billing_invoice_id', 'payment_method_id', 'provider', 'label', 'currency', 'amount_minor', 'status', 'redirect_url', 'message', 'created_by'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'unknown'], true);
    }
}
