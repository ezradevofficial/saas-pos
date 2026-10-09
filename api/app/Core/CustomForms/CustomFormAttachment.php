<?php

namespace App\Core\CustomForms;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * CF-04: a file attached to a custom form record, on the media disk at
 * `tenants/{tenant}/custom-forms/{type key}/{uuid}.{ext}` (as custom field
 * files, ADR 011). Uploaded first (record_id null, its uploader's only),
 * tied to the record that names it on save; served through a temporary
 * signed URL only.
 */
class CustomFormAttachment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['type_id', 'record_id', 'disk', 'path', 'name', 'mime', 'size', 'uploaded_by'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
