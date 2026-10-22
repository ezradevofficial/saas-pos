<?php

namespace App\Core\Notifications\Sms;

/** Sends a text message to an E.164 number. Bound to a provider per environment. */
interface SmsSender
{
    /**
     * The SMS_DRIVER values this release implements. Any other value binds
     * NullSmsSender (sending throws SmsNotConfigured); `app:preflight` warns.
     */
    public const DRIVERS = ['log'];

    public function send(string $to, string $message): void;
}
