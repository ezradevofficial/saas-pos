<?php

namespace App\Core\Automation\Webhooks;

use RuntimeException;

/**
 * The webhook URL may not be called (SSRF protection): not HTTPS, carries
 * credentials, does not resolve, or resolves to an address that is not
 * public. `reason` is a translation key under automation.webhook.refused.
 */
class WebhookRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Webhook refused: {$reason}");
    }
}
