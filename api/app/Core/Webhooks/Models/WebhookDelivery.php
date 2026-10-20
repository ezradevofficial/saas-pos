<?php

namespace App\Core\Webhooks\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HOOK-01: one event for one subscription (an outbox row), with the body
 * as sent (already without the principal's hidden fields) and the last
 * attempt's answer: status code, the first `webhooks.response_bytes` of
 * the body and the duration. `error` is a reason code the apps translate
 * (`unreachable`, `status`, `refused.<reason>`, `disabled`, `principal`).
 * Kept `webhooks.retention_days`, then deleted by `webhooks:sweep`.
 *
 * @property string $id
 * @property string $subscription_id
 * @property string $event_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property ?array<string, mixed> $occurrence where the event happened (WebhookOccurrence::scopeArray)
 * @property string $status
 * @property int $attempts
 * @property ?int $response_status
 * @property ?string $response_body
 * @property ?int $duration_ms
 * @property ?string $error
 * @property ?CarbonImmutable $next_attempt_at
 * @property ?CarbonImmutable $last_attempt_at
 * @property ?CarbonImmutable $delivered_at
 * @property ?string $replay_of
 * @property ?string $requested_by
 * @property CarbonImmutable $created_at
 */
class WebhookDelivery extends Model
{
    use BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const RETRYING = 'retrying';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    public const STATUSES = [self::PENDING, self::SENDING, self::RETRYING, self::DELIVERED, self::FAILED];

    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'subscription_id', 'event_id', 'event_type', 'payload', 'occurrence', 'status', 'attempts', 'response_status', 'response_body',
        'duration_ms', 'error', 'next_attempt_at', 'last_attempt_at', 'delivered_at', 'replay_of', 'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurrence' => 'array',
            'attempts' => 'integer',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }
}
