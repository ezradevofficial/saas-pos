<?php

namespace Modules\Salon\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-02: a service of the menu (a core `service` item with salon data).
 *
 * @property string $company_id
 * @property string $item_id
 * @property string $name
 * @property ?string $category
 * @property string $kind
 * @property int $duration_minutes
 * @property int $buffer_minutes
 * @property bool $online_bookable
 * @property list<string> $staff_ids
 * @property list<string> $resource_ids
 * @property list<string> $addon_ids
 * @property list<array{item_id: string, qty: string}> $products
 */
class Service extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    protected $table = 'salon_services';

    protected string $auditModule = 'salon';

    protected string $auditResource = 'service';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer', 'buffer_minutes' => 'integer', 'online_bookable' => 'boolean',
            'staff_ids' => 'array', 'resource_ids' => 'array', 'addon_ids' => 'array', 'products' => 'array',
        ];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }
}
