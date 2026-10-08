<?php

namespace App\Core\Notifications\Models;

use App\Core\Audit\Audited;
use App\Core\Audit\Auditor;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * NOT-03: a tenant's own text for one event type, channel (or `all`) and
 * language, in place of the default from the language files. Reset to
 * the default deletes the row (configuration, not a business record);
 * create, change and reset are audited as `core.notification_template.*`.
 */
class NotificationTemplate extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $fillable = ['event_type', 'channel', 'locale', 'subject', 'body', 'updated_by'];

    protected static function booted(): void
    {
        static::deleted(function (self $template) {
            app(Auditor::class)->record(
                'core.notification_template.reset',
                $template,
                $template->only(['event_type', 'channel', 'locale', 'subject', 'body']),
            );
        });
    }

    /** The deletion and its audit entry commit or roll back together. */
    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(fn () => parent::delete());
    }
}
