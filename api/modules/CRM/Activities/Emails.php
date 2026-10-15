<?php

namespace Modules\CRM\Activities;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Modules\CRM\Models\Activity;
use Modules\CRM\Support\Tx;

/**
 * CRM-29, NOT-02: an email to a customer or lead, sent through the core
 * mailer (SendCrmEmail: the tenant's verified sender when set, BR-06;
 * queued after commit, encrypted on the queue) and logged as a done
 * `email` activity with the recipient and subject. Rate-limited per user
 * (429 `too_many_emails`, as document emails).
 */
class Emails
{
    public const PER_MINUTE = 10;

    public const PER_DAY = 300;

    public function __construct(
        private readonly Activities $activities,
        private readonly TenantContext $tenants,
    ) {}

    /** @param array<string, mixed> $data validated input */
    public function send(array $data, User $by): Activity
    {
        $minute = 'crm-email:m:'.$by->id;
        $day = 'crm-email:d:'.$by->id;

        if (RateLimiter::tooManyAttempts($minute, self::PER_MINUTE) || RateLimiter::tooManyAttempts($day, self::PER_DAY)) {
            throw new ApiException(429, 'too_many_emails', __('templates.errors.too_many_emails'));
        }

        RateLimiter::hit($minute, 60);
        RateLimiter::hit($day, 86400);

        return Tx::run(function () use ($data, $by) {
            $activity = $this->activities->create([
                ...array_intersect_key($data, array_flip(['branch_id', 'lead_id', 'opportunity_id', 'quote_id', 'party_id'])),
                'kind' => 'email',
                'subject' => mb_substr((string) $data['subject'], 0, 200),
                'notes' => $data['body'],
                'starts_at' => CarbonImmutable::now()->toIso8601String(),
                'email' => ['to' => $data['to'], 'subject' => $data['subject']],
            ], $by);
            $activity->forceFill(['status' => Activity::DONE, 'done_at' => CarbonImmutable::now(), 'email' => ['to' => $data['to'], 'subject' => $data['subject'], 'sent' => 'queued']])->save();

            SendCrmEmail::dispatch($this->tenants->require(), $activity->id, (string) $data['to'], (string) $data['subject'], (string) $data['body'], (string) ($by->locale ?: 'en'))->afterCommit();

            return $activity->refresh();
        });
    }
}
