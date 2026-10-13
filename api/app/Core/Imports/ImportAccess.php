<?php

namespace App\Core\Imports;

use App\Core\Identity\Models\User;
use App\Core\MasterData\CompanyReach;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who reaches imports (IMP-01, RBAC-01, RBAC-04): `core.import.run` plus
 * the importer's own permissions on its entity (Importer::canRun), and:
 *
 * - the person who uploaded the file sees the import, changes its mapping
 *   and starts it (the rows are written as them);
 * - anyone else sees an import only once it ran, and only if their scope
 *   covers every company of the records it touched (`scope_companies`,
 *   "shared" for shared records) with the entity's read permission. An
 *   import whose records reach beyond a reader's scope is not found;
 * - undo: those who see it and may run the importer in its mode; each row
 *   is then undone through the entity's own policy and writers as them;
 * - the uploaded file's rows (preview, error and result files) go to the
 *   uploader only: they are the uploader's own data, and other readers'
 *   field rules (RBAC-05) may hide columns the file holds.
 */
class ImportAccess
{
    public const SHARED = 'shared';

    /** Imports other readers may see: those that ran. */
    public const SHARED_STATUSES = [Import::COMPLETED, Import::FAILED, Import::UNDOING, Import::PARTLY_UNDONE, Import::UNDONE];

    public function __construct(
        private readonly ImportRegistry $registry,
        private readonly CompanyReach $reach,
    ) {}

    public function view(User $user, Import $import): bool
    {
        $importer = $this->registry->find($import->importer);

        if ($importer === null || ! $importer->canRun($user)) {
            return false;
        }

        if ($this->isUploader($user, $import)) {
            return true;
        }

        return in_array($import->status, self::SHARED_STATUSES, true) && $this->covers($user, $importer, (array) $import->scope_companies);
    }

    public function change(User $user, Import $import): bool
    {
        return $this->isUploader($user, $import) && ($this->registry->find($import->importer)?->canRun($user, $import->mode) ?? false);
    }

    public function undo(User $user, Import $import): bool
    {
        return $this->view($user, $import) && ($this->registry->find($import->importer)?->canRun($user, $import->mode) ?? false);
    }

    /** The file's own rows (preview, error and result files): the uploader's. */
    public function files(User $user, Import $import): bool
    {
        return $this->isUploader($user, $import) && $this->view($user, $import);
    }

    public function isUploader(User $user, Import $import): bool
    {
        return $import->user_id === $user->id;
    }

    /**
     * The imports $user sees, as a query filter (the same rules as view()).
     */
    public function visible(Builder $query, User $user): Builder
    {
        $importers = $this->registry->runnable($user);

        return $query->where(function (Builder $q) use ($importers, $user) {
            $q->whereRaw('false');

            foreach ($importers as $importer) {
                $q->orWhere(function (Builder $one) use ($importer, $user) {
                    $one->where('importer', $importer->key())->where(function (Builder $who) use ($importer, $user) {
                        $who->where('user_id', $user->id)->orWhere(function (Builder $others) use ($importer, $user) {
                            $others->whereIn('status', self::SHARED_STATUSES);
                            $companies = $this->reach->companyIds($user, $importer->readPermissions());

                            if ($companies !== null) {
                                $others->whereRaw('scope_companies <@ ?::jsonb', [json_encode([self::SHARED, ...$companies])]);
                            }
                        });
                    });
                });
            }
        });
    }

    /** @param list<string> $companies */
    private function covers(User $user, Importer $importer, array $companies): bool
    {
        foreach ($companies as $company) {
            $ok = $company === self::SHARED
                ? $this->reach->anywhere($user, $importer->readPermissions())
                : $this->reach->reachesRecord($user, $company, $importer->readPermissions());

            if (! $ok) {
                return false;
            }
        }

        return true;
    }
}
