<?php

namespace App\Core\Privacy\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * NFR-08: one marketing consent decision (granted or withdrawn) for a
 * subject, purpose and channel. Append-only (a database trigger refuses
 * edits and deletes): the latest row is the current state, the rest is the
 * evidence.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $purpose
 * @property string $channel
 * @property bool $granted
 * @property string $source
 * @property ?string $recorded_by
 * @property CarbonImmutable $recorded_at
 * @property ?string $evidence
 */
class ConsentRecord extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['subject_type', 'subject_id', 'purpose', 'channel', 'granted', 'source', 'recorded_by', 'recorded_at', 'evidence'];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Consent records are append-only.'));
        static::deleting(fn () => throw new LogicException('Consent records are append-only.'));
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
