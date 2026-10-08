<?php

namespace App\Core\Automation\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One webhook call of a run (AUTO-03), written in the run's transaction
 * (an outbox) and sent afterwards by SendWebhookDelivery with its own
 * retries. `url` is encrypted (it may carry a token); `payload` is the
 * JSON built from the committed document.
 *
 * @property string $id
 * @property string $run_id
 * @property string $rule_id
 * @property int $action_index
 * @property string $url
 * @property array $payload
 * @property string $status pending, sending, retrying, delivered, failed
 * @property int $attempts
 * @property ?int $response_status
 * @property ?string $response_body
 * @property ?string $error
 */
class WebhookDelivery extends Model
{
    use BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const RETRYING = 'retrying';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    protected $table = 'automation_webhook_deliveries';

    protected $fillable = [
        'run_id', 'rule_id', 'action_index', 'url', 'payload', 'status', 'attempts',
        'response_status', 'response_body', 'error', 'next_attempt_at', 'delivered_at',
    ];

    protected $hidden = ['url', 'payload'];

    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'payload' => 'array',
            'action_index' => 'integer',
            'attempts' => 'integer',
            'response_status' => 'integer',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'run_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }

    /** The id receivers see (X-Webhook-Id): the same on every attempt. */
    public function webhookId(): string
    {
        return $this->run_id.':'.$this->action_index;
    }
}
