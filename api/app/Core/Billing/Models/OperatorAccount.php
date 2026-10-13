<?php

namespace App\Core\Billing\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The operator's record of one customer tenant (platform console: the
 * Tenants list): name, country, plan, status, MRR and trial end, kept in
 * step by the billing service whenever the subscription changes. A row of
 * the operator tenant, so staff read it inside that tenant's context and
 * never through another customer's tables. Not audited itself: every
 * change it mirrors is audited on the subscription.
 *
 * @property string $id
 * @property string $customer_tenant_id
 * @property string $name
 * @property string $country
 * @property string $currency
 * @property string $plan
 * @property string $cycle
 * @property string $status
 * @property int $mrr_minor
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_end
 * @property int|null $grace_days
 * @property list<array<string, string>> $purge_due
 */
class OperatorAccount extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['customer_tenant_id', 'name', 'country', 'currency', 'plan', 'cycle', 'status', 'mrr_minor', 'trial_ends_at', 'current_period_end', 'grace_days', 'purge_due'];

    protected $attributes = ['purge_due' => '[]'];

    protected function casts(): array
    {
        return [
            'mrr_minor' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_period_end' => 'date',
            'grace_days' => 'integer',
            'purge_due' => 'array',
        ];
    }
}
