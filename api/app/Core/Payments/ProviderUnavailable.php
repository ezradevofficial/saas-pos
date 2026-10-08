<?php

namespace App\Core\Payments;

use RuntimeException;
use Throwable;

/**
 * The provider could not be reached, timed out or answered with a server
 * error: nothing is known about the request. The message is safe to show
 * (no credentials, no provider body).
 */
class ProviderUnavailable extends RuntimeException
{
    public function __construct(string $message = 'The payment provider could not be reached.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
