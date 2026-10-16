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
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /** MFG-03: the draft version, if any (one per BOM). */
    public function draftVersion(): HasOne
    {
        return $this->hasOne(BomVersion::class)->where('status', BomVersion::DRAFT);
    }

    /** MFG-03: published versions, the one in force first (load with inForceFirst() to batch a page). */
    public function publishedVersions(): HasMany
    {
        return $this->hasMany(BomVersion::class)->where('status', BomVersion::PUBLISHED);
    }

    /**
     * Eager loads for a page of BOMs (review item 4): the published versions in
     * force by $at, latest first (the first is the one in force), and the draft.
     *
     * @return array<string, Closure|string>
     */
    public static function versionLoads(CarbonImmutable $at): array
    {
        return [
            'publishedVersions' => fn ($query) => $query->where('effective_from', '<=', $at->format('Y-m-d H:i:s.uP'))
                ->orderByDesc('effective_from')->orderByDesc('version_no'),
            'draftVersion:id,bom_id,status',
        ];
    }
}
