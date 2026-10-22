<?php

namespace Modules\Payroll\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PAY-02: a company's payroll settings: overtime, night, public-holiday and
 * rest-day multipliers, the standard monthly hours and days that turn a
 * monthly salary into hourly and daily rates, and whether premiums are
 * taxable and contributory. Company data, never statutory figures.
 *
 * @property string $company_id
 * @property ?string $overtime_multiplier
 * @property ?string $night_multiplier
 * @property ?string $holiday_multiplier
 * @property ?string $rest_day_multiplier
 * @property ?string $monthly_hours
 * @property ?string $monthly_days
 * @property bool $premiums_taxable
 * @property bool $premiums_contributory
 * @property ?array<string, array{reason: string, marked_by: ?string, marked_at: string}> $not_applicable CP-01: pack components the company does not owe
 */
class Settings extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    public const MULTIPLIERS = ['overtime_multiplier', 'night_multiplier', 'holiday_multiplier', 'rest_day_multiplier'];

    protected $table = 'payroll_settings';

    protected string $auditModule = 'payroll';

    protected string $auditResource = 'settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['premiums_taxable' => 'boolean', 'premiums_contributory' => 'boolean', 'not_applicable' => 'array'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
