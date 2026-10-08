<?php

namespace App\Core\Currency\Models;

use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * CUR-07: a shop rate that moved more than the company's tolerance from
 * the previous rate of its pair. The rate is still saved; the alert is
 * audited as `core.exchange_rate.alert`. Notification delivery comes with
 * the notification centre (phase 3).
 */
class RateAlert extends Model implements HasScope
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'exchange_rate_id', 'pair', 'previous_mid', 'new_mid', 'change_percent', 'entered_by'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
