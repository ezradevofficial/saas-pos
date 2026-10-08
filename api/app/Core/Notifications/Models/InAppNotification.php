<?php

namespace App\Core\Notifications\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NOT-01: one in-app notification in a user's inbox (the bell), already
 * rendered in the user's language. The user marks it read and archives
 * it; it is never deleted. Inbox state is the user's own and is not
 * written to the audit log.
 */
class InAppNotification extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'notifications';

    protected $fillable = ['user_id', 'event_type', 'subject', 'body', 'link', 'data', 'read_at', 'archived_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
