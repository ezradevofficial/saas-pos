<?php

namespace App\Core\Approvals\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * APR-06: a user lets another act on their approvals from `starts_on` to
 * `ends_on` (dates in each request's company time zone), for every
 * document type or the listed ones, until revoked.
 *
 * @property string $id
 * @property string $from_user_id
 * @property string $to_user_id
 * @property ?list<string> $document_types
 */
class ApprovalDelegation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['from_user_id', 'to_user_id', 'starts_on', 'ends_on', 'document_types', 'note', 'created_by', 'revoked_at', 'revoked_by'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'document_types' => 'array',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /** Whether it lets the delegate act on a request of $documentType on $date (Y-m-d, the request's company's day). */
    public function covers(string $documentType, string $date): bool
    {
        return $this->revoked_at === null
            && $this->starts_on->format('Y-m-d') <= $date && $this->ends_on->format('Y-m-d') >= $date
            && ($this->document_types === null || in_array($documentType, $this->document_types, true));
    }
}
