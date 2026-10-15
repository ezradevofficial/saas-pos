<?php

namespace Modules\FixedAssets\Models;

use App\Core\Audit\Audited;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FA-06: a file attached to an asset (stored on the media disk; archived, never deleted). Audited as `fixed_assets.document.*`. */
class AssetDocument extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $table = 'fa_asset_documents';

    protected string $auditModule = 'fixed_assets';

    protected string $auditResource = 'document';

    protected array $auditHidden = ['path', 'disk'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime', 'size' => 'integer'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
