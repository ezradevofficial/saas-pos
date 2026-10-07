<?php

namespace App\Core\Notifications\Sms;

/** Sends a text message to an E.164 number. Bound to a provider per environment. */
interface SmsSender
{
    public function send(string $to, string $message): void;
}
