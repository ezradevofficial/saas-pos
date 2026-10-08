<?php

namespace App\Core\Sync;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\IncrementalSource;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\Contracts\SyncSource;
use InvalidArgumentException;

/**
 * NFR-04: the entities a POS device can pull. Core registers its own in
 * SyncServiceProvider; a module registers its sources from its service
 * provider (the POS module adds its settings, number ranges and shifts):
 *
 *     $this->app->afterResolving(SyncSources::class, fn (SyncSources $s) => $s->register(new NumberRangeSource));
 *
 * or app(SyncSources::class)->register(...) in boot(). Entities of a module
 * the tenant has not activated are not served (RBAC-08).
 *
 * Staff rows carry the permissions that matter at the till: those whose
 * names start with a registered till prefix (`pos.` by default; a module
 * adds its own with tillPermissionPrefix()).
 */
class SyncSources
{
    /** @var array<string, SyncSource> in registration order */
    private array $sources = [];

    /** @var list<string> */
    private array $tillPrefixes = ['pos.'];

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function register(SyncSource $source): void
    {
        $key = $source->key();

        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid sync entity key [{$key}].");
        }

        if (! $source instanceof IncrementalSource && ! $source instanceof SnapshotSource) {
            throw new InvalidArgumentException("Sync source [{$key}] must be incremental or a snapshot.");
        }

        if (isset($this->sources[$key]) && $source::class !== $this->sources[$key]::class) {
            throw new InvalidArgumentException("Sync entity [{$key}] is already registered.");
        }

        $this->sources[$key] = $source;
    }

    public function tillPermissionPrefix(string $prefix): void
    {
        if (! in_array($prefix, $this->tillPrefixes, true)) {
            $this->tillPrefixes[] = $prefix;
        }
    }

    /** @return list<string> */
    public function tillPermissionPrefixes(): array
    {
        return $this->tillPrefixes;
    }

    /** @return list<string> every registered key, whatever the tenant has */
    public function keys(): array
    {
        return array_keys($this->sources);
    }

    /**
     * The sources the current tenant may pull, in registration order.
     *
     * @return array<string, SyncSource>
     */
    public function available(): array
    {
        $active = array_flip($this->modules->active());

        return array_filter($this->sources, fn (SyncSource $source) => isset($active[$source->module()]));
    }

    public function mode(SyncSource $source): string
    {
        return $source instanceof IncrementalSource ? SyncSource::INCREMENTAL : SyncSource::SNAPSHOT;
    }
}
