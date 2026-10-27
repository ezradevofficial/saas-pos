<?php

namespace Modules\Hotel\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * HOT-02: a company's hotel settings. A company without a row uses the
 * defaults (see defaults()); none of them is a statutory number (every one
 * is "to confirm", docs/modules/hotel.md).
 *
 * @property string $company_id
 * @property string $check_in_time
 * @property string $check_out_time
 * @property string $night_audit_time
 * @property int $early_checkin_fee_minor
 * @property int $late_checkout_fee_minor
 * @property ?string $fee_currency
 * @property list<int> $weekend_days
 * @property int $cancel_free_days
 * @property string $cancel_charge_kind
 * @property int $cancel_charge_value
 * @property string $noshow_charge_kind
 * @property int $noshow_charge_value
 * @property int $deposit_percent
 * @property bool $require_guest_id
 * @property ?string $room_item_id
 * @property list<string> $levy_tax_code_ids
 * @property ?int $charge_limit_minor
 * @property ?string $room_charge_method_id
 */
class Settings extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const CHARGE_KINDS = ['none', 'nights', 'percent', 'fixed'];

    protected string $auditModule = 'hotel';

    protected string $auditResource = 'settings';

    protected $table = 'hotel_settings';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'early_checkin_fee_minor' => 'integer',
            'late_checkout_fee_minor' => 'integer',
            'weekend_days' => 'array',
            'cancel_free_days' => 'integer',
            'cancel_charge_value' => 'integer',
            'noshow_charge_value' => 'integer',
            'deposit_percent' => 'integer',
            'require_guest_id' => 'boolean',
            'levy_tax_code_ids' => 'array',
            'charge_limit_minor' => 'integer',
        ];
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'check_in_time' => '14:00',
            'check_out_time' => '11:00',
            'night_audit_time' => '03:00',
            'early_checkin_fee_minor' => 0,
            'late_checkout_fee_minor' => 0,
            'fee_currency' => null,
            'weekend_days' => [5, 6],
            'cancel_free_days' => 1,
            'cancel_charge_kind' => 'nights',
            'cancel_charge_value' => 1,
            'noshow_charge_kind' => 'nights',
            'noshow_charge_value' => 1,
            'deposit_percent' => 0,
            'require_guest_id' => true,
            'room_item_id' => null,
            'levy_tax_code_ids' => [],
            'charge_limit_minor' => null,
            'room_charge_method_id' => null,
        ];
    }

    /** The settings of $companyId, defaults filled in (an unsaved instance when none is stored). */
    public static function for(string $companyId): self
    {
        return self::query()->where('company_id', $companyId)->first()
            ?? (new self(['company_id' => $companyId, ...self::defaults()]));
    }
}
