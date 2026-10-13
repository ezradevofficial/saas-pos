<?php

namespace App\Core\Imports;

use App\Core\Identity\Models\User;
use InvalidArgumentException;

/**
 * IMP-01: the importers modules register (core: items, customers,
 * suppliers, item prices). Keys are global; what a user may run follows
 * their permissions (Importer::canRun).
 */
class ImportRegistry
{
    /** @var array<string, Importer> */
    private array $importers = [];

    public function register(Importer $importer): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}\z/', $importer->key()) !== 1) {
            throw new InvalidArgumentException("Invalid importer key [{$importer->key()}].");
        }

        $this->importers[$importer->key()] = $importer;
    }

    public function find(string $key): ?Importer
    {
        return $this->importers[$key] ?? null;
    }

    public function get(string $key): Importer
    {
        return $this->find($key) ?? throw new InvalidArgumentException("Unknown importer [{$key}].");
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->importers);
    }

    /** @return list<Importer> the importers $user may run */
    public function runnable(User $user): array
    {
        return array_values(array_filter($this->importers, fn (Importer $importer) => $importer->canRun($user)));
    }
}
