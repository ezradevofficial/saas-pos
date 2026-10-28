<?php

namespace App\Core\Billing\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SUB-04: a payment method the tenant saved for its subscription: a mobile
 * money wallet (the phone number, encrypted) or a card (the provider's
 * token from the hosted checkout, encrypted; never card data; for
 * Paystack also the payer's email, encrypted, which a saved-card charge needs). `label` is
 * what the screens show (a masked phone, the card's last digits).
 * `auto_renew`: renewals are requested through it automatically, where
 * the provider allows (a push to the phone, a saved card). Archived, never
 * deleted (TEN-06); audited without the phone or token (AUD-01).
 *
 * @property string $id
 * @property string $kind
 * @property string $provider
 * @property string $label
 * @property string|null $phone
 * @property string|null $token
 * @property string|null $customer_email
 * @property bool $is_default
 * @property bool $auto_renew
 */
class BillingPaymentMethod extends Model
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const MOBILE_MONEY = 'mobile_money';

    public const CARD = 'card';

    protected $fillable = ['kind', 'provider', 'label', 'phone', 'token', 'customer_email', 'is_default', 'auto_renew', 'created_by'];

    protected $hidden = ['phone', 'token', 'customer_email'];

    protected array $auditHidden = ['phone', 'token', 'customer_email'];

    protected function casts(): array
    {
        return [
            'phone' => 'encrypted',
            'token' => 'encrypted',
            'customer_email' => 'encrypted',
            'is_default' => 'boolean',
            'auto_renew' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }
}
