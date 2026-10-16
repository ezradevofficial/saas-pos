<?php

namespace Modules\FixedAssets\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FA-15, FA-16: an asset revalued to a fair value, or impaired to its recoverable amount. Audited as `fixed_assets.revaluation.*`. */
class Revaluation extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'fa_revaluations';

    protected string $auditModule = 'fixed_assets';

    protected string $auditResource = 'revaluation';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['revalued_on' => 'immutable_date', 'before' => 'array', 'after' => 'array'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
