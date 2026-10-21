<?php

namespace App\Core\Api\Sandbox;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * API-05 (review): an inactive developer sandbox closes on $closesOn; its
 * Owners are told by email beforehand, in their language. Account mail:
 * sent for real, never kept in the sandbox outbox.
 */
class SandboxClosingSoon extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $sandboxName,
        public readonly string $closesOn,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $locale = method_exists($notifiable, 'preferredLocale') ? $notifiable->preferredLocale() : 'en';

        return (new MailMessage)
            ->subject(__('api.sandbox.closing.subject', ['name' => $this->sandboxName], $locale))
            ->line(__('api.sandbox.closing.body', ['name' => $this->sandboxName, 'date' => $this->closesOn], $locale))
            ->line(__('api.sandbox.closing.keep', [], $locale));
    }
}
