<?php

namespace App\Core\Billing\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * SUB-03: the tenant's copy of an invoice the operator issued to it (the
 * read model). Written only by the billing service, in this tenant's own
 * context, from the operator's invoice (`operator_invoice_id`); the
 * tenant never reads the operator's tables. Lines and totals are in minor
 * units of `currency`; line names are kept in English and French from the
 * catalogue. Audited (AUD-01).
 *
 * @property string $id
 * @property string $subscription_id
 * @property string $operator_invoice_id
 * @property string $number
 * @property string $kind
 * @property string $status
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon $issued_at
 * @property Carbon $due_at
 * @property Carbon|null $paid_at
 * @property list<array<string, mixed>> $lines
 * @property array<string, mixed> $seller
 * @property array<string, mixed> $customer
 */
class BillingInvoice extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const OPEN = 'open';

    public const PAID = 'paid';

    public const VOID = 'void';

    public const STATUSES = [self::OPEN, self::PAID, self::VOID];

    public const KINDS = ['first', 'renewal', 'upgrade'];

    protected $fillable = [
        'id', 'subscription_id', 'operator_invoice_id', 'number', 'kind', 'status', 'currency', 'subtotal_minor',
        'discount_minor', 'tax_minor', 'total_minor', 'period_start', 'period_end', 'issued_at', 'due_at', 'paid_at',
        'lines', 'seller', 'customer',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'lines' => 'array',
            'seller' => 'array',
            'customer' => 'array',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
