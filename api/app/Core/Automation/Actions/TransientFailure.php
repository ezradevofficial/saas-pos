<?php

namespace App\Core\Automation\Actions;

use RuntimeException;

/**
 * An action failed in a way a later attempt may fix (a webhook receiver
 * that is down or answered 5xx). The message is translated and safe for
 * the run log; the run is retried (AUTO-05).
 */
class TransientFailure extends RuntimeException {}
