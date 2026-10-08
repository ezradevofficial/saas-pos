<?php

namespace App\Core\Automation\Webhooks;

/** A webhook URL with the one public address it will be sent to (resolved once). */
final class WebhookTarget
{
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly string $ip,
    ) {}

    /** The CURLOPT_RESOLVE entry pinning the host to the checked address. */
    public function resolveEntry(): string
    {
        $ip = str_contains($this->ip, ':') ? '['.$this->ip.']' : $this->ip;

        return "{$this->host}:{$this->port}:{$ip}";
    }
}
