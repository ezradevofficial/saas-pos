<?php

namespace Modules\FixedAssets\Models;

use App\Core\Audit\Audited;
use App\Core\Currency\FxSnapshot;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FA-17: an asset sold, scrapped or written off, through its flow, and what it posted. Audited as `fixed_assets.disposal.*`. */
class Disposal extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'fa_disposals';

    protected string $auditModule = 'fixed_assets';

    protected string $auditResource = 'disposal';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['disposal_date' => 'immutable_date', 'decided_at' => 'immutable_datetime', 'posted_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'fx' => FxSnapshot::class.':fx'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
