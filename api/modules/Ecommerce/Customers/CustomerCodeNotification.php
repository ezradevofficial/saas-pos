<?php

namespace Modules\Ecommerce\Customers;

use App\Core\Notifications\Channels\SmsChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * ECOM-08: a customer's sign-in code by SMS or email. Like core's
 * VerificationCode (ADR 009), it never goes through the notifier: it must
 * leave at once and its text must never be stored (the code is kept only
 * as an HMAC).
 */
class CustomerCodeNotification extends Notification
{
    public function __construct(
        public readonly string $code,
        public readonly string $channel,
        public readonly string $storeName,
        public readonly int $minutes,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channel === 'sms' ? [SmsChannel::class] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('ecommerce.customers.code.subject', ['store' => $this->storeName]))
            ->line(__('ecommerce.customers.code.line', ['code' => $this->code]))
            ->line(__('ecommerce.customers.code.expiry', ['minutes' => $this->minutes]))
            ->line(__('ecommerce.customers.code.ignore'))
            ->salutation($this->storeName);
    }

    public function toSms(object $notifiable): string
    {
        return __('ecommerce.customers.code.sms', ['store' => $this->storeName, 'code' => $this->code, 'minutes' => $this->minutes]);
    }
}
