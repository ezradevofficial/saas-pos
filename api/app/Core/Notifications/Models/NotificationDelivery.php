<?php

namespace App\Core\Notifications\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NOT-06: one message to one user on one channel, with its status:
 *
 * - queued: waiting for SendDelivery (again after a failed attempt, from
 *   `next_attempt_at`); sending: claimed by a job, being handed over;
 * - sent: handed to the mailer or provider; delivered: the provider
 *   reported it delivered (in-app is delivered at once);
 * - failed: every attempt failed (`error` holds a code for the last
 *   error, translated as notifications.delivery_errors.*; the raw error
 *   goes to the log only);
 * - skipped: not sent, `reason` says why (no email address, channel not
 *   configured);
 * - pending_digest: email held for the user's daily or weekly digest
 *   (NOT-05); digested: carried by the digest delivery `digest_id`.
 *
 * Rows are the delivery log itself and are not audited separately.
 */
class NotificationDelivery extends Model
{
    use BelongsToTenant, HasUuids;

    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const PENDING_DIGEST = 'pending_digest';

    public const DIGESTED = 'digested';

    public const STATUSES = [self::QUEUED, self::SENDING, self::SENT, self::DELIVERED, self::FAILED, self::SKIPPED, self::PENDING_DIGEST, self::DIGESTED];

    /** Why a delivery was skipped (translated as notifications.reasons.*). */
    public const REASON_NO_EMAIL = 'no_email';

    public const REASON_NO_PHONE = 'no_phone';

    public const REASON_CHANNEL_UNAVAILABLE = 'channel_unavailable';

    public const REASON_USER_DEACTIVATED = 'user_deactivated';

    /** Safe error codes (notifications.delivery_errors.*): the mailer or provider refused it, or the job died. */
    public const ERROR_SEND_FAILED = 'send_failed';

    public const ERROR_JOB_FAILED = 'job_failed';

    /** The event type of a digest email (NOT-05); not a registered event type. */
    public const DIGEST_EVENT = 'core.notification.digest';

    protected $fillable = [
        'user_id', 'notification_id', 'digest_id', 'event_type', 'channel', 'status', 'reason', 'recipient',
        'locale', 'subject', 'body', 'link', 'digest', 'attempts', 'error', 'provider_message_id',
        'next_attempt_at', 'sent_at', 'delivered_at', 'failed_at',
    ];

    protected $attributes = ['attempts' => 0];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
