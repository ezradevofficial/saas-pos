<?php

namespace Modules\FixedAssets\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FA-18: a meter reading of an asset. Audited as `fixed_assets.reading.*`. */
class Reading extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'fa_readings';

    protected string $auditModule = 'fixed_assets';

    protected string $auditResource = 'reading';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_on' => 'immutable_date'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
