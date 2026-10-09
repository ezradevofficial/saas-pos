<?php

namespace App\Core\CustomFields;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * CF-01: a file of a file field, on the media disk at
 * `tenants/{tenant}/custom-fields/{entity}/{uuid}.{ext}`. Uploaded first
 * (record_id null, usable only by its uploader), then tied to the record
 * that stores its id on save. Served only through a temporary signed URL
 * (CustomFieldFiles::url).
 */
class CustomFieldFile extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['entity', 'field_key', 'record_id', 'disk', 'path', 'name', 'mime', 'size', 'uploaded_by'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
