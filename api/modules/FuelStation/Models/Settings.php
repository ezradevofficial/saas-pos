<?php

namespace Modules\FuelStation\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** FUEL-03: a company's fuel settings; a company without a row uses the defaults (every tolerance 0). */
class Settings extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected string $auditModule = 'fuel';

    protected string $auditResource = 'settings';

    protected $table = 'fuel_settings';

    protected $guarded = ['id', 'tenant_id'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return ['cash_variance_tolerance_minor' => 0, 'litre_tolerance' => '0.000', 'loss_tolerance_percent' => '0.0000', 'loss_tolerance_litres' => '0.000', 'delivery_tolerance_percent' => '0.0000'];
    }

    /** The settings of $companyId, defaults filled in (an unsaved instance when none is stored). */
    public static function for(string $companyId): self
    {
        return self::query()->where('company_id', $companyId)->first() ?? new self(['company_id' => $companyId, ...self::defaults()]);
    }
}
