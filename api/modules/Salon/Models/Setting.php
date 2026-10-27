<?php

namespace Modules\Salon\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-10, SAL-11, SAL-14, SAL-26: a company's salon settings. No row means the defaults
 * (Support\Settings).
 *
 * @property string $company_id
 * @property int $slot_minutes
 * @property int $cancel_window_hours
 * @property string $late_cancel_fee_percent
 * @property string $no_show_fee_percent
 * @property string $deposit_percent
 * @property bool $deposit_online_required
 * @property int $hold_minutes
 * @property int $reminder_hours
 * @property int $guest_retention_days
 * @property ?string $tip_item_id
 * @property ?string $fee_item_id
 * @property ?string $commission_item_code
 */
class Setting extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'salon_settings';

    protected string $auditModule = 'salon';

    protected string $auditResource = 'settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'slot_minutes' => 'integer', 'cancel_window_hours' => 'integer', 'hold_minutes' => 'integer', 'reminder_hours' => 'integer',
            'guest_retention_days' => 'integer', 'deposit_online_required' => 'boolean',
        ];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
