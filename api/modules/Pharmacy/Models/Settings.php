<?php

namespace Modules\Pharmacy\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PHA-02: a company's pharmacy settings. A company without a row uses the
 * defaults (see defaults()); none of them is a regulator's number (every
 * one is "to confirm", docs/modules/pharmacy.md).
 *
 * @property string $company_id
 * @property int $near_expiry_days
 * @property int $min_sale_days
 * @property list<string> $prescription_classes
 * @property list<string> $pharmacist_classes
 * @property ?int $prescription_valid_days
 * @property bool $quarantine_expired
 */
class Settings extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'pharmacy_settings';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'near_expiry_days' => 'integer',
            'min_sale_days' => 'integer',
            'prescription_classes' => 'array',
            'pharmacist_classes' => 'array',
            'prescription_valid_days' => 'integer',
            'quarantine_expired' => 'boolean',
        ];
    }

    /** @return array{near_expiry_days: int, min_sale_days: int, prescription_classes: list<string>, pharmacist_classes: list<string>, prescription_valid_days: ?int, quarantine_expired: bool} */
    public static function defaults(): array
    {
        return [
            'near_expiry_days' => 90,
            'min_sale_days' => 0,
            'prescription_classes' => ['prescription_only', 'controlled'],
            'pharmacist_classes' => ['prescription_only', 'controlled', 'poison'],
            'prescription_valid_days' => null,
            'quarantine_expired' => true,
        ];
    }

    /** The settings of $companyId, defaults filled in (an unsaved instance when none is stored). */
    public static function for(string $companyId): self
    {
        return self::query()->where('company_id', $companyId)->first()
            ?? (new self(['company_id' => $companyId, ...self::defaults()]));
    }
}
