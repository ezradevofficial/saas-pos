<?php

namespace App\Core\Imports;

use App\Core\Identity\Models\User;

/**
 * IMP-01: who imports, in which mode, and what importers look up once per
 * batch (units by code, categories by name...) instead of once per row.
 */
final class ImportContext
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        public readonly User $user,
        public readonly string $mode,
    ) {}

    public function updates(): bool
    {
        return $this->mode === 'upsert';
    }

    /**
     * @template T
     *
     * @param  callable(): T  $resolve
     * @return T
     */
    public function remember(string $key, callable $resolve): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $resolve();
        }

        return $this->memo[$key];
    }

    /** A new batch: look things up again (they may have changed). */
    public function forget(): void
    {
        $this->memo = [];
    }
}
