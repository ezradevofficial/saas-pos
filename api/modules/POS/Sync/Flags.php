<?php

namespace Modules\POS\Sync;

/**
 * POS-09: what the server noticed about an uploaded record but did not
 * refuse (the device wins for completed sales): a code, the line it is
 * about (1-based) when there is one, and details for review.
 */
final class Flags
{
    /** @var list<array{code: string, line?: int, detail?: array<string, mixed>}> */
    private array $flags = [];

    /** @param array<string, mixed> $detail */
    public function add(string $code, ?int $line = null, array $detail = []): void
    {
        $this->flags[] = array_filter(['code' => $code, 'line' => $line, 'detail' => $detail], fn ($v) => $v !== null && $v !== []);
    }

    /** @return list<array{code: string, line?: int, detail?: array<string, mixed>}> */
    public function all(): array
    {
        return $this->flags;
    }
}
