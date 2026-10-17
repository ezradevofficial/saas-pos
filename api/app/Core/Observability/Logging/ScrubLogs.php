<?php

namespace App\Core\Observability\Logging;

use Illuminate\Log\Logger;

/**
 * NFR-10: the `tap` of every log channel (config/logging.php). Adds the
 * scrubber, so no channel writes a secret or personal data.
 */
final class ScrubLogs
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(new ScrubProcessor);
    }
}
