<?php

namespace App\Core\Webhooks\Models;

use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HOOK-01: a webhook subscription (docs/adr/021-webhooks.md). `url`,
 * `secret` and `previous_secret` are encrypted, never returned by the API
 * (only `url_display`, the URL without its query) and denied in the full
 * export (EXP-02). Archived, never deleted (TEN-06).
 *
 * @property string $id
 * @property string $principal_type
 * @property string $principal_id
 * @property string $url
 * @property string $url_display
 * @property ?string $description
 * @property list<string> $events
 * @property bool $active
 * @property ?string $company_id
 * @property ?string $branch_id
 * @property string $secret
 * @property ?string $previous_secret
 * @property ?CarbonImmutable $previous_secret_expires_at
 * @property ?CarbonImmutable $secret_rotated_at
 * @property int $consecutive_failures
 * @property ?string $disabled_reason
 * @property ?CarbonImmutable $disabled_at
 * @property ?CarbonImmutable $last_delivery_at
 * @property string $created_by
 * @property ?string $updated_by
 * @property ?CarbonImmutable $archived_at
 */
class WebhookSubscription extends Model
{
    use BelongsToTenant, HasUuids;

    public const FAILING = 'failing';

    protected $table = 'webhook_subscriptions';

    protected $fillable = [
        'principal_type', 'principal_id', 'url', 'url_display', 'description', 'events', 'active',
        'company_id', 'branch_id', 'secret', 'previous_secret', 'previous_secret_expires_at', 'secret_rotated_at',
        'consecutive_failures', 'disabled_reason', 'disabled_at', 'last_delivery_at', 'created_by', 'updated_by', 'archived_at',
    ];

    protected $hidden = ['url', 'secret', 'previous_secret'];

    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
            'consecutive_failures' => 'integer',
            'previous_secret_expires_at' => 'immutable_datetime',
            'secret_rotated_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'last_delivery_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }

    public function isLive(): bool
    {
        return $this->active && $this->archived_at === null;
    }

    /**
     * The secrets deliveries are signed with now: the current one, and the
     * previous one while its overlap lasts (receivers switch without gaps).
     *
     * @return list<string>
     */
    public function signingSecrets(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $secrets = [$this->secret];

        if ($this->previous_secret !== null && $this->previous_secret_expires_at !== null && $this->previous_secret_expires_at->greaterThan($at)) {
            $secrets[] = $this->previous_secret;
        }

        return $secrets;
    }
}
