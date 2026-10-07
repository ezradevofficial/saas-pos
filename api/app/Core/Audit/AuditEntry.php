<?php

namespace App\Core\Audit;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One audit log entry (AUD-01, AUD-02). Written only by Auditor::record.
 * Entries are never updated or deleted (AUD-03): the database refuses it
 * (trigger and revoked privileges) and so does this model.
 */
class AuditEntry extends Model
{
    use BelongsToTenant, HasUuids;

    public const MESSAGE_APPEND_ONLY = 'audit log is append-only';

    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $guarded = [];

    // Keep microseconds: they are part of the hashed occurred_at.
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'device_time' => 'immutable_datetime',
            'before' => 'array',
            'after' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException(self::MESSAGE_APPEND_ONLY));
        static::deleting(fn () => throw new LogicException(self::MESSAGE_APPEND_ONLY));
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
