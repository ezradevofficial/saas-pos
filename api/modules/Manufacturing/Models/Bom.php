<?php

namespace Modules\Manufacturing\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\Rbac\HasScope;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Archivable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MFG-02: a bill of materials (`production`, made to stock) or a recipe
 * (`recipe`, made to order and backflushed at the till) of a company's
 * item, with numbered versions (MFG-03) and custom fields (MFG-22).
 * Archived, never deleted (TEN-06); audited as `manufacturing.bom.*`.
 *
 * @property string $id
 * @property string $company_id
 * @property string $item_id
 * @property string $kind
 * @property ?string $name
 * @property array<string, mixed>|null $custom
 */
class Bom extends Model implements HasScope
{
    use Archivable, Audited, BelongsToTenant, HasUuids;

    public const PRODUCTION = 'production';

    public const RECIPE = 'recipe';

    public const KINDS = [self::PRODUCTION, self::RECIPE];

    protected $table = 'mfg_boms';

    protected string $auditModule = 'manufacturing';

    protected string $auditResource = 'bom';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['custom' => 'array'];
    }

    public function scope(): Scope
    {
        return Scope::company($this->company_id);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BomVersion::class)->orderBy('version_no');
    }
}
