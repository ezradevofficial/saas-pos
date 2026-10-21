<?php

namespace App\Core\Billing\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ADM-06: one status change of a customer's account, recorded by
 * Accounts::sync in the operator tenant (trials started and converted,
 * churn). Append-only bookkeeping for the revenue reports; the change
 * itself is audited on the subscription.
 *
 * @property string $id
 * @property string $customer_tenant_id
 * @property string $kind
 * @property string|null $from_status
 * @property string $to_status
 * @property string $country
 * @property string $currency
 * @property string|null $bundle
 * @property int $mrr_minor
 * @property Carbon $occurred_at
 */
class OperatorAccountEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const TRIAL_STARTED = 'trial_started';

    public const SUBSCRIBED = 'subscribed';

    public const CONVERTED = 'converted';

    public const CHURNED = 'churned';

    public const SUSPENDED = 'suspended';

    public const REACTIVATED = 'reactivated';

    protected $fillable = ['customer_tenant_id', 'kind', 'from_status', 'to_status', 'country', 'currency', 'bundle', 'mrr_minor', 'occurred_at'];

    protected function casts(): array
    {
        return ['mrr_minor' => 'integer', 'occurred_at' => 'datetime'];
    }
}
