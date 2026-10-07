<?php

namespace App\Core\Identity\Notifications;

use App\Core\Notifications\Channels\SmsChannel;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AUTH-05: an invitation link, by email or by SMS to the invited contact,
 * in the tenant's default language. Holding the link proves the contact.
 */
class InvitationNotification extends Notification
{
    public function __construct(
        public readonly string $token,
        public readonly string $channel,
        public readonly string $name,
        public readonly string $tenantName,
        public readonly string $inviterName,
        public readonly CarbonInterface $expiresAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channel === 'sms' ? [SmsChannel::class] : ['mail'];
    }

    public function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/invitations/'.$this->token;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('auth.notifications.invitation.subject', ['tenant' => $this->tenantName, 'app' => config('app.name')]))
            ->greeting(__('auth.notifications.greeting', ['name' => $this->name]))
            ->line(__('auth.notifications.invitation.line', ['inviter' => $this->inviterName, 'tenant' => $this->tenantName]))
            ->action(__('auth.notifications.invitation.action'), $this->url())
            ->line(__('auth.notifications.invitation.expiry', ['date' => $this->expiresAt->toDayDateTimeString()]))
            ->line(__('auth.notifications.verification_code.ignore'))
            ->salutation(config('app.name'));
    }

    public function toSms(object $notifiable): string
    {
        return __('auth.notifications.invitation.sms', [
            'app' => config('app.name'),
            'tenant' => $this->tenantName,
            'url' => $this->url(),
        ]);
    }
}
