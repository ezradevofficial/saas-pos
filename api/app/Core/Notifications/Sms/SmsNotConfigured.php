<?php

namespace App\Core\Notifications\Sms;

use RuntimeException;

class SmsNotConfigured extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No SMS provider is configured. Set SMS_DRIVER (config services.sms.driver) to a real provider for this environment.');
    }
}
