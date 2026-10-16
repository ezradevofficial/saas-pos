<?php

namespace Modules\CRM\Models;

use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRM-03: a lead source, won reason or lost reason of a company.
 *
 * @property string $company_id
 * @property string $kind
 * @property string $name
 * @property int $position
 */
class Option extends CrmModel
{
    use Archivable;

    public const SOURCE = 'source';

    public const WON_REASON = 'won_reason';

    public const LOST_REASON = 'lost_reason';

    public const KINDS = [self::SOURCE, self::WON_REASON, self::LOST_REASON];

    protected $table = 'crm_options';

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
