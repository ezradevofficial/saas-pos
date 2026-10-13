<?php

namespace App\Core\Imports\Jobs;

use App\Core\Identity\Models\User;
use App\Core\Imports\Import;
use App\Core\Imports\ImportRunner;
use App\Core\Imports\ImportUndo;
use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\App;
use Throwable;

/**
 * IMP-02, IMP-03: a dry run, an import or an undo, in the background, in
 * the import's tenant (TenantAware: the runtime connection under RLS,
 * never the owner's, TEN-01), in the language the import was started in.
 * Not retried: a batch either committed or rolled back, and the import
 * records how far it got.
 */
class RunImport implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const CHECK = 'check';

    public const IMPORT = 'import';

    public const UNDO = 'undo';

    public int $tries = 1;

    /** 50,000 rows at a few milliseconds each, with room to spare. */
    public int $timeout = 3600;

    public function __construct(
        public string $tenantId,
        public string $importId,
        public string $action,
        public ?string $userId = null,
    ) {
        $this->afterCommit();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(ImportRunner $runner, ImportUndo $undo): void
    {
        $import = Import::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        $locale = App::getLocale();
        App::setLocale($import->locale);

        try {
            match ($this->action) {
                self::CHECK => $runner->check($import),
                self::IMPORT => $runner->import($import),
                self::UNDO => $this->undo($undo, $import),
            };
        } finally {
            App::setLocale($locale);
        }
    }

    private function undo(ImportUndo $undo, Import $import): void
    {
        $user = User::query()->find($this->userId);

        try {
            $blockers = $user === null ? ['count' => 0, 'rows' => []] : $undo->undo($import, $user);
        } catch (Throwable $e) {
            report($e);
            $blockers = ['count' => 0, 'rows' => [], 'failed' => true];
        }

        if ($blockers !== null) {
            // Refused (something now depends) or failed: back to where it was.
            $import->refresh()->forceFill([
                'status' => $import->finishedStatus(),
                'blocking' => $blockers['rows'],
                'failure' => ($blockers['failed'] ?? false) || $user === null ? __('imports.errors.undo_failed') : null,
            ])->save();
        }
    }

    /** A worker crash or timeout: the import says so instead of running forever. */
    public function failed(?Throwable $e): void
    {
        $import = app(TenantContext::class)->run($this->tenantId, fn () => Import::query()->find($this->importId));

        if ($import === null || ! $import->isRunning()) {
            return;
        }

        app(TenantContext::class)->run($this->tenantId, fn () => $import->forceFill([
            'status' => match ($import->status) {
                Import::CHECKING => Import::READY,
                Import::UNDOING => $import->finishedStatus(),
                default => Import::FAILED,
            },
            'failure' => __('imports.errors.failed'),
            'finished_at' => $import->finished_at ?? now(),
        ])->save());
    }
}
