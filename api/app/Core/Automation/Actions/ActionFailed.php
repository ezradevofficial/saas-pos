<?php

namespace App\Core\Automation\Actions;

use RuntimeException;

/**
 * An action refused in a way retrying cannot fix (AUTO-05): the document is
 * gone, the type no longer offers the capability, the workflow blocks the
 * move. The message is translated and safe for the run log; the run fails
 * at once and administrators are alerted.
 */
class ActionFailed extends RuntimeException {}
