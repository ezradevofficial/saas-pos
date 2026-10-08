<?php

namespace App\Core\Payments\Models;

use App\Core\Audit\Audited;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money a provider reports as received on the company's Till or Paybill
 * (an M-Pesa C2B confirmation), once per provider transaction id. Matched
 * to an open payment intent of the same amount (and account reference),
 * or `unmatched` until someone with `core.payment.match` matches it in
 * the back office. Only what matching needs is kept: no payer names.
 */
class PaymentReceipt extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const STATUSES = ['matched', 'unmatched'];

    protected $fillable = [
        'company_id', 'payment_method_id', 'provider', 'receipt', 'currency', 'amount_minor', 'account_reference',
        'shortcode', 'transacted_at', 'status', 'payment_intent_id', 'matched_by', 'matched_at', 'provider_data',
    ];

    protected array $auditHidden = ['provider_data'];

    protected $attributes = ['provider_data' => '{}'];

    protected function casts(): array
    {
        return [
            'provider_data' => 'array',
            'amount_minor' => 'integer',
            'transacted_at' => 'datetime',
            'matched_at' => 'datetime',
        ];
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class, 'payment_intent_id');
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
