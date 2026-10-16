<?php

namespace Modules\Manufacturing\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * MFG-29: a company's labour and overhead rates per labour hour (minor
 * units of its base currency; configured by the company, not statutory)
 * and whether releasing a production order needs approval. Audited as
 * `manufacturing.settings.*`.
 *
 * @property string $company_id
 * @property int|string $labour_rate_minor
 * @property int|string $overhead_rate_minor
 * @property string $currency
 * @property bool $release_approval
 */
class Setting extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'mfg_settings';

    protected string $auditModule = 'manufacturing';

    protected string $auditResource = 'settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['release_approval' => 'boolean'];
    }
}
