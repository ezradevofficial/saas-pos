<?php

namespace App\Core\Branding\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * BR-02, BR-04: a logo, favicon or sign-in background, a file on the
 * `media` disk under `tenants/{tenant}/branding/`. The theme
 * configuration names assets by id. Files are never served from a public
 * path: readers get a signed URL (BrandAssets::url).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind
 * @property string $disk
 * @property string $path
 * @property string $mime
 */
class BrandAsset extends Model
{
    use BelongsToTenant, HasUuids;

    public const LOGO = 'logo';

    public const FAVICON = 'favicon';

    public const BACKGROUND = 'background';

    public const KINDS = [self::LOGO, self::FAVICON, self::BACKGROUND];

    protected $fillable = ['kind', 'disk', 'path', 'mime', 'size', 'width', 'height', 'created_by'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }
}
