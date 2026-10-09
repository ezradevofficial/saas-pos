<?php

namespace App\Core\Configuration\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a configuration document (LAY-06): a draft is edited;
 * publishing makes it immutable (a database trigger refuses changes to a
 * published or archived payload, and every delete); the previously
 * published version is archived. A discarded draft is archived with
 * discarded_at set and is never offered for roll back.
 *
 * @property string $id
 * @property string $document_id
 * @property int $version
 * @property int $revision
 * @property string $status
 * @property array $payload
 */
class ConfigVersion extends Model
{
    use BelongsToTenant, HasUuids;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    /**
     * Every column but the payload: lists and history load these, so a
     * page of documents never reads their (up to 256 KB) payloads.
     */
    public const SUMMARY = [
        'id', 'tenant_id', 'document_id', 'version', 'revision', 'status', 'source', 'source_version_id',
        'created_by', 'updated_by', 'published_by', 'published_at', 'archived_at',
        'discarded_at', 'discarded_by', 'created_at', 'updated_at',
    ];

    protected $fillable = [
        'document_id', 'version', 'revision', 'status', 'payload', 'source', 'source_version_id',
        'created_by', 'updated_by', 'published_by', 'published_at', 'archived_at',
        'discarded_at', 'discarded_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'version' => 'integer',
            'revision' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'discarded_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ConfigDocument::class, 'document_id');
    }
}
