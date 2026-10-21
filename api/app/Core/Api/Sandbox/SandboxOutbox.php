<?php

namespace App\Core\Api\Sandbox;

use App\Core\Identity\Notifications\VerificationCode;
use App\Core\Tenancy\TenantContext;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * API-05 (review): a developer sandbox sends no email, SMS or WhatsApp.
 * The Notifier's deliveries (SendDelivery) and any other mail sent in a
 * sandbox's context are kept in `sandbox_outbox` instead, for the
 * developer to read; in-app notifications are recorded as usual. Account
 * mail still goes out: sign-in and verification codes, and the sandbox's
 * closing notice.
 */
class SandboxOutbox
{
    /** Notifications sent for real even from a sandbox. */
    public const ACCOUNT_MAIL = [VerificationCode::class, SandboxClosingSoon::class];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Sandbox $sandbox,
    ) {}

    public function capturing(): bool
    {
        return $this->tenants->id() !== null && $this->sandbox->active();
    }

    public function record(string $channel, ?string $recipient, ?string $subject, string $body, string $source): void
    {
        DB::connection(TenantContext::CONNECTION)->table('sandbox_outbox')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenants->require(),
            'channel' => $channel,
            'recipient' => $recipient === null ? null : Str::limit($recipient, 250, ''),
            'subject' => $subject === null ? null : Str::limit($subject, 250, ''),
            'body' => $body,
            'source' => $source,
            'created_at' => now(),
        ]);
    }

    /** MessageSending: false (not sent) for mail sent in a sandbox, after keeping it. */
    public function onMessageSending(MessageSending $event): ?bool
    {
        if (! $this->capturing() || in_array($event->data['__laravel_notification'] ?? null, self::ACCOUNT_MAIL, true)) {
            return null;
        }

        $message = $event->message;
        $to = $message instanceof Email ? implode(', ', array_map(fn ($address) => $address->getAddress(), $message->getTo())) : null;
        $subject = $message instanceof Email ? $message->getSubject() : null;
        $body = $message instanceof Email ? (string) ($message->getTextBody() ?? strip_tags((string) $message->getHtmlBody())) : '';

        $this->record('email', $to, $subject, $body, 'mail');

        return false;
    }
}
