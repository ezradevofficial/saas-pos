<?php

namespace Modules\CRM\Activities;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Lead;
use Modules\CRM\Support\CrmAccess;
use Modules\CRM\Support\CrmError;
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
        private readonly CrmAccess $access,
    ) {}

    /**
     * CRM-29 (review): an email goes only to an address of the linked lead
     * (seen by the sender) or customer, never to any address typed.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertRecipient(array $data, User $by): void
    {
        $addresses = [];

        if (($data['lead_id'] ?? null) !== null) {
            $lead = Lead::query()->find($data['lead_id']);

            if ($lead === null || ! $this->access->sees($by, $lead, 'lead')) {
                throw CrmError::make('link_unknown', field: 'lead_id');
            }

            $addresses[] = (string) $lead->email;
        }

        if (($data['party_id'] ?? null) !== null) {
            $party = Party::query()->find($data['party_id']);
            $addresses = [...$addresses, ...array_map(fn ($email) => (string) ($email['address'] ?? ''), (array) ($party?->emails ?? []))];
        }

        if (! in_array(mb_strtolower((string) $data['to']), array_map('mb_strtolower', array_filter($addresses)), true)) {
            throw CrmError::make('email_not_of_recipient', field: 'to');
        }
    }

    /** @param array<string, mixed> $data validated input */
    public function send(array $data, User $by): Activity
    {
        $this->assertRecipient($data, $by);
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
