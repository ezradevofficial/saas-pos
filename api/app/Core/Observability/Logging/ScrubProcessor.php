<?php

namespace App\Core\Observability\Logging;

use App\Core\Observability\Scrubber;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * NFR-10: every log record is scrubbed with the shared rules before any
 * handler writes it: the message, its context (exceptions become a
 * scrubbed summary) and extra data. The request id and tenant id stay.
 */
final class ScrubProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: Scrubber::scrubString($record->message),
            context: (array) Scrubber::scrub($record->context),
            extra: (array) Scrubber::scrub($record->extra),
        );
    }
}
