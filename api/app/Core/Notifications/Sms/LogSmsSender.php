<?php

namespace App\Core\Notifications\Sms;

use Illuminate\Support\Facades\Log;

/** Local and test driver: writes the message to the log instead of sending it. */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        Log::info('SMS', ['to' => $to, 'message' => $message]);
    }
}
