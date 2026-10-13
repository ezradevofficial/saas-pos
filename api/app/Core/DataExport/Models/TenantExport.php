<?php

namespace App\Core\DataExport\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EXP-02: one full export of the tenant's data, requested by an Owner.
 * queued → running → ready (a zip on the media disk until expires_at) →
 * expired (file deleted, row kept, TEN-06); or failed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $requested_by
 * @property string $status
 * @property ?string $disk
 * @property ?string $path
 * @property ?int $size
 * @property ?string $sha256
 * @property ?int $table_count
 * @property ?int $row_count
 * @property ?int $file_count
 * @property ?string $failure
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $completed_at
 * @property ?CarbonImmutable $expires_at
 * @property ?CarbonImmutable $downloaded_at
 * @property ?CarbonImmutable $created_at
 */
class TenantExport extends Model
{
    use BelongsToTenant, HasUuids;

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const ACTIVE = [self::QUEUED, self::RUNNING];

    protected $fillable = ['requested_by', 'status'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'table_count' => 'integer',
            'row_count' => 'integer',
            'file_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'downloaded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isDownloadable(?CarbonImmutable $at = null): bool
    {
        return $this->status === self::READY
            && $this->path !== null
            && $this->expires_at !== null
            && $this->expires_at->isAfter($at ?? CarbonImmutable::now());
    }
}
