<?php

namespace Modules\Loyalty\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** LOY-09: a coupon code of a promotion, with its usage limit and the uses counted on the server. */
class Coupon extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected string $auditModule = 'loyalty';

    protected string $auditResource = 'coupon';

    /** `uses` changes with every sale; only the code, its limit and archiving are audited. */
    protected array $auditHidden = ['uses'];

    protected $table = 'loyalty_coupons';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['max_uses' => 'integer', 'uses' => 'integer'];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /** Uses left, or null when unlimited (never below zero). */
    public function remaining(): ?int
    {
        return $this->max_uses === null ? null : max(0, $this->max_uses - $this->uses);
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
