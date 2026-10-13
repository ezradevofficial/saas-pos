<?php

namespace App\Core\Billing\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SUB-03: an invoice the operator issues to a customer tenant, a document
 * of the operator tenant's selling company (KE, CD). It is the fiscal
 * source document the fiscal queue sends to eTIMS or the DGI
 * (BillingFiscalSource) and the reference of its payment intents
 * (`billing.invoice`). The customer's copy is BillingInvoice. Lines are
 * tax-inclusive, in minor units; `tax_rate` is the operator company's
 * rate on the issue date (null when the country pack has none yet).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $customer_tenant_id
 * @property string $subscription_id
 * @property string $number
 * @property string $kind
 * @property string $status
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property string|null $tax_code_id
 * @property string|null $tax_rate
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon $issued_at
 * @property Carbon $due_at
 * @property Carbon|null $paid_at
 * @property string|null $paid_via
 * @property string|null $payment_reference
 * @property list<array<string, mixed>> $lines
 * @property array<string, mixed> $customer
 */
class OperatorInvoice extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected string $auditResource = 'operator_invoice';

    protected $fillable = [
        'id', 'company_id', 'customer_tenant_id', 'subscription_id', 'number', 'kind', 'status', 'currency',
        'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor', 'tax_code_id', 'tax_rate', 'period_start',
        'period_end', 'issued_at', 'due_at', 'paid_at', 'paid_via', 'payment_reference', 'overpayments', 'lines', 'customer',
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
            'overpayments' => 'array',
            'lines' => 'array',
            'customer' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isOpen(): bool
    {
        return $this->status === BillingInvoice::OPEN;
    }
}
