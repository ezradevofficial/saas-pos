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
 * One request for money through a payment provider (concept note 7.1):
 *
 * - `stk`: a push to the customer's phone for a sale (M-Pesa STK push);
 * - `manual`: the cashier confirms a payment with the provider's reference
 *   (an M-Pesa code), offline or when the push fails; it stays
 *   `verification = unverified` until the provider confirms the code (a
 *   C2B confirmation or a transaction status check), then `verified` or
 *   `mismatch` (flagged for the back office);
 * - `payout`: a refund paid back to the customer (M-Pesa B2C);
 * - `direct`: cash and other methods with nothing to ask the provider.
 *
 * Statuses: pending, unknown (the push was sent but the provider's
 * answer was lost: still checked), succeeded, failed, cancelled (the
 * customer declined), timeout. A paid result arriving late moves an
 * `unknown` or `timeout` intent to `succeeded`. The phone number is encrypted and only ever shown masked; the
 * provider's answer is kept to its result code and text, amount, receipt
 * and dates (`provider_data`), never names. Created, finished and every
 * status change are audited (AUD-01), without the phone.
 */
class PaymentIntent extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const STATUSES = ['pending', 'unknown', 'succeeded', 'failed', 'cancelled', 'timeout'];

    /** Final statuses a paid result may still turn into `succeeded` (the push may have gone through). */
    public const RECOVERABLE = ['unknown', 'timeout'];

    public const FINAL = ['succeeded', 'failed', 'cancelled', 'timeout'];

    public const MODES = ['direct', 'stk', 'manual', 'payout'];

    public const VERIFICATIONS = ['unverified', 'verified', 'mismatch'];

    protected $fillable = [
        'id', 'company_id', 'location_id', 'device_id', 'payment_method_id', 'provider', 'driver', 'purpose', 'mode',
        'currency', 'amount_minor', 'phone', 'reference_type', 'reference', 'account_reference', 'status', 'verification',
        'provider_request_id', 'provider_checkout_id', 'provider_receipt', 'verification_ref', 'result_code', 'result_message',
        'provider_data', 'original_intent_id', 'expires_at', 'verify_after', 'completed_at', 'created_by',
    ];

    protected $hidden = ['phone'];

    protected array $auditHidden = ['phone', 'provider_data'];

    protected $attributes = ['provider_data' => '{}'];

    protected function casts(): array
    {
        return [
            'phone' => 'encrypted',
            'provider_data' => 'array',
            'amount_minor' => 'integer',
            'expires_at' => 'datetime',
            'verify_after' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_intent_id');
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'unknown'], true);
    }

    /** The phone with all but the country code and last three digits hidden ("2547******123"). */
    public function maskedPhone(): ?string
    {
        return self::mask($this->phone);
    }

    public static function mask(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $keep = min(4, max(0, strlen($phone) - 3));

        return substr($phone, 0, $keep).str_repeat('*', max(0, strlen($phone) - $keep - 3)).substr($phone, -3);
    }

    public function scope(): Scope
    {
        return $this->location_id !== null ? Scope::location($this->location_id) : Scope::company($this->company_id);
    }
}
