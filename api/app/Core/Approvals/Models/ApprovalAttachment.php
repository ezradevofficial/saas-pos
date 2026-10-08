<?php

namespace App\Core\Approvals\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A file attached to a request (APR-03), on the media disk at
 * `tenants/{tenant}/approvals/{request}/{uuid}.{ext}`; served only through
 * a temporary signed URL to people who can see the request.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $request_id
 * @property string $uploaded_by
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string $mime
 * @property int $size
 */
class ApprovalAttachment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['request_id', 'uploaded_by', 'disk', 'path', 'name', 'mime', 'size'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
