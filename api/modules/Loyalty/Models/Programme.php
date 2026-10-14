<?php

namespace Modules\Loyalty\Models;

use App\Core\Audit\Audited;
use App\Core\MasterData\Items\Item;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** LOY-01: a company's loyalty programme (earn, redeem, expiry, credit and gift card settings). */
class Programme extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected string $auditModule = 'loyalty';

    protected string $auditResource = 'programme';

    protected $table = 'loyalty_programmes';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'earn_points' => 'integer',
            'expiry_months' => 'integer',
            'expiry_notice_days' => 'integer',
            'credit_warning_percent' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function giftCardItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'gift_card_item_id');
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
