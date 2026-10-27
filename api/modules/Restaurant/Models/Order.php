<?php

namespace Modules\Restaurant\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** REST-03, REST-04: an order of a table or a tab. The device makes its id; `rev` rises with every change. */
class Order extends Model implements HasScope
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'rest_orders';

    protected string $auditModule = 'restaurant';

    protected string $auditResource = 'order';

    protected array $auditHidden = ['rev', 'header_at'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['flags' => 'array', 'opened_at' => 'datetime', 'header_at' => 'datetime', 'bill_requested_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function scope(): Scope
    {
        return Scope::location($this->location_id);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class, 'order_id')->orderBy('created_at_device')->orderBy('id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'order_id')->orderBy('created_at');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class, 'order_id')->orderBy('settled_at');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class, 'table_id');
    }
}
