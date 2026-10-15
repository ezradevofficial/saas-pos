<?php

namespace Modules\CRM\Models;

use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRM-30: a sales target for a user, team or branch over a period, in the
 * company's base currency, against one metric.
 *
 * @property string $company_id
 * @property ?string $branch_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $metric
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property string $currency
 * @property int $amount_minor
 */
class Target extends CrmModel
{
    use Archivable;

    public const SUBJECTS = ['user', 'team', 'branch'];

    public const WON_VALUE = 'won_value';

    public const POS_SALES = 'pos_sales';

    public const INVOICED = 'invoiced';

    public const METRICS = [self::WON_VALUE, self::POS_SALES, self::INVOICED];

    protected $table = 'crm_targets';

    protected function casts(): array
    {
        return ['period_start' => 'immutable_date', 'period_end' => 'immutable_date', 'amount_minor' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
