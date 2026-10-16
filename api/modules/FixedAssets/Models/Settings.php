<?php

namespace Modules\FixedAssets\Models;

use App\Core\Audit\Audited;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** FA-01: a company's depreciation convention and tax book switch. Audited as `fixed_assets.settings.*`. */
class Settings extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'fa_settings';

    protected string $auditModule = 'fixed_assets';

    protected string $auditResource = 'settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tax_book' => 'boolean'];
    }
}
