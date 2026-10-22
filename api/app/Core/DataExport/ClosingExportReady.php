<?php

namespace App\Core\DataExport;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * EXP-02, NFR-08: the business account closed; its Owner gets the full data
 * export by a signed link that works without signing in, until $expiresOn.
 * Account mail, sent by email only and never kept in a notification log:
 * the link is the credential.
 */
class ClosingExportReady extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $url,
        public readonly string $expiresOn,
        public readonly string $size,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = method_exists($notifiable, 'preferredLocale') ? ($notifiable->preferredLocale() ?? 'en') : 'en';
        $replace = ['name' => $this->tenantName, 'date' => $this->expiresOn, 'size' => $this->size];

        return (new MailMessage)
            ->subject(__('data_export.closing.mail.subject', $replace, $locale))
            ->line(__('data_export.closing.mail.body', $replace, $locale))
            ->action(__('data_export.closing.mail.action', $replace, $locale), $this->url)
            ->line(__('data_export.closing.mail.expiry', $replace, $locale))
            ->line(__('data_export.closing.mail.private', $replace, $locale));
    }
}
