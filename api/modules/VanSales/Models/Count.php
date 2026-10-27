<?php

namespace Modules\VanSales\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** VAN-18 to VAN-23: an end-of-day count and its reconciliation. */
class Count extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'vs_counts';

    protected string $auditModule = 'van_sales';

    protected string $auditResource = 'count';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'written_off_at' => 'datetime',
        ];
    }

    public function scope(): Scope
    {
        return Scope::branch($this->branch_id);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CountLine::class, 'count_id');
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(CountTender::class, 'count_id');
    }
}
