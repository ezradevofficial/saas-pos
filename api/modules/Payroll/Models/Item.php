<?php

namespace Modules\Payroll\Models;

use App\Core\Audit\Audited;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PAY-04: an earning or deduction of a company, with the flags the
 * statutory engine reads (taxable, contributory; pre-tax, insurance
 * premium). The company sets them; nothing is assumed.
 *
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property bool $taxable
 * @property bool $contributory
 * @property bool $pre_tax
 * @property bool $insurance_premium
 */
class Item extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const EARNING = 'earning';

    public const DEDUCTION = 'deduction';

    protected $table = 'payroll_items';

    protected string $auditModule = 'payroll';

    protected string $auditResource = 'item';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['taxable' => 'boolean', 'contributory' => 'boolean', 'pre_tax' => 'boolean', 'insurance_premium' => 'boolean'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
