<?php

namespace App\Core\Notifications\Models;

use App\Core\Audit\Audited;
use App\Core\Audit\Auditor;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * NOT-03: a tenant's own text for one event type and channel (or `all`),
 * in place of the default from the language files. One text, written in
 * the organisation's language; every recipient gets it whatever their app
 * language (owner decision 2026-10-08). Reset to
 * the default deletes the row (configuration, not a business record);
 * create, change and reset are audited as `core.notification_template.*`.
 */
class NotificationTemplate extends Model
{
    use Audited, BelongsToTenant, HasUuids;

    protected $fillable = ['event_type', 'channel', 'subject', 'body', 'updated_by'];

    protected static function booted(): void
    {
        static::deleted(function (self $template) {
            app(Auditor::class)->record(
                'core.notification_template.reset',
                $template,
                $template->only(['event_type', 'channel', 'subject', 'body']),
            );
        });
    }

    /** The deletion and its audit entry commit or roll back together. */
    public function delete(): ?bool
    {
        return $this->getConnection()->transaction(fn () => parent::delete());
    }
}
