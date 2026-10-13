<?php

namespace App\Core\Imports;

use App\Core\Identity\Models\User;

/**
 * Who reaches imports (IMP-01, RBAC-01): `core.import.run` plus the
 * importer's own permissions on its entity (Importer::canRun).
 *
 * - An import is seen by those who may run its importer (the tenant's
 *   imports only: RLS, TEN-01).
 * - Its mapping is changed, and its dry run and import started, by the
 *   person who uploaded it, as they still may run it in its mode: the
 *   rows are written as them.
 * - Undo: anyone who may run the importer in the import's mode.
 */
class ImportAccess
{
    public function __construct(private readonly ImportRegistry $registry) {}

    public function view(User $user, Import $import): bool
    {
        return $this->registry->find($import->importer)?->canRun($user) ?? false;
    }

    public function change(User $user, Import $import): bool
    {
        return $import->user_id === $user->id && ($this->registry->find($import->importer)?->canRun($user, $import->mode) ?? false);
    }

    public function undo(User $user, Import $import): bool
    {
        return $this->registry->find($import->importer)?->canRun($user, $import->mode) ?? false;
    }

    /** @return list<string> the importers whose imports $user sees */
    public function visibleImporters(User $user): array
    {
        return array_map(fn (Importer $importer) => $importer->key(), $this->registry->runnable($user));
    }
}
