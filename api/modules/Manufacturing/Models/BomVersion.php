<?php

namespace Modules\Manufacturing\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Uom;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MFG-03, MFG-04: one version of a BOM: `output_qty` of the output item in
 * `output_uom_id` (one batch, `output_qty_base` in its base unit), the
 * labour minutes a batch takes and its lines. A `draft` is edited freely;
 * a `published` version is fixed and in force from `effective_from` until
 * the next published version's date. Audited as
 * `manufacturing.bom_version.*`.
 *
 * @property string $id
 * @property string $bom_id
 * @property int $version_no
 * @property string $status
 * @property ?CarbonImmutable $effective_from
 * @property string $output_qty
 * @property string $output_uom_id
 * @property string $output_qty_base
 * @property int $labour_minutes
 */
class BomVersion extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    protected $table = 'mfg_bom_versions';

    protected string $auditModule = 'manufacturing';

    protected string $auditResource = 'bom_version';

    protected $guarded = [];

    /** Microseconds kept: two versions published the same second still order. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'labour_minutes' => 'integer', 'effective_from' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BomLine::class)->orderBy('line_no');
    }

    public function outputUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'output_uom_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }
}
