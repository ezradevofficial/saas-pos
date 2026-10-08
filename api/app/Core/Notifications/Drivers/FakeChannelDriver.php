<?php

namespace App\Core\Notifications\Drivers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local and test driver: keeps every message in memory and writes it to
 * the log; reports it delivered. Tests make it fail with failNext().
 * Refused outside local and testing (EnvironmentGuard, NFR-06).
 */
class FakeChannelDriver implements ChannelDriver
{
    /** @var list<OutgoingMessage> */
    public array $sent = [];

    private int $failures = 0;

    private string $error = 'Provider unavailable';

    public function __construct(public readonly string $channel) {}

    /** The next $times sends throw $error. */
    public function failNext(int $times = 1, string $error = 'Provider unavailable'): void
    {
        $this->failures = $times;
        $this->error = $error;
    }

    public function send(OutgoingMessage $message): DriverResult
    {
        if ($this->failures > 0) {
            $this->failures--;

            throw new RuntimeException($this->error);
        }

        $this->sent[] = $message;
        Log::info('Notification ('.$this->channel.', fake driver)', ['to' => $message->to, 'subject' => $message->subject, 'body' => $message->body]);

        return new DriverResult('fake-'.Str::lower(Str::random(12)), delivered: true);
    }
}
