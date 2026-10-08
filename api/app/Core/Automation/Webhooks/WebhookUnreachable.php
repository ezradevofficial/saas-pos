<?php

namespace App\Core\Automation\Webhooks;

use RuntimeException;
use Throwable;

/** No answer from the webhook receiver (refused, timed out, TLS failure): retried. */
class WebhookUnreachable extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Webhook unreachable', 0, $previous);
    }
}
