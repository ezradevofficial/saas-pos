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
 *
 * Runs once: on the `imports` queue, worked by Horizon's
 * `supervisor-imports` on the `redis-imports` connection, whose
 * retry_after is longer than the timeout; and the job carries its run's
 * token, which one worker claims (Import::claim) before doing anything. A
 * second delivery, or a job of an earlier run, does nothing. Every status
 * change is conditional on the status it expects (Import::transition).
 */
class RunImport implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const CHECK = 'check';

    public const IMPORT = 'import';

    public const UNDO = 'undo';

    public const RUNNING = [self::CHECK => Import::CHECKING, self::IMPORT => Import::IMPORTING, self::UNDO => Import::UNDOING];

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public string $tenantId,
        public string $importId,
        public string $action,
        public string $runToken,
        public ?string $userId = null,
    ) {
        $this->timeout = (int) config('imports.timeout', 3600);
        $this->onQueue(config('imports.queue', 'imports'));
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

        if ($import === null || ! $import->claim($this->runToken, self::RUNNING[$this->action])) {
            return;
        }

        $import->refresh();
        $locale = App::getLocale();
        App::setLocale($import->locale);

        try {
            match ($this->action) {
                self::CHECK => $runner->check($import),
                self::IMPORT => $runner->import($import),
                self::UNDO => $undo->undo($import, User::query()->find($this->userId)),
            };
        } finally {
            App::setLocale($locale);
        }
    }

    /** A worker crash or timeout: the import says so instead of running forever, unless it moved on. */
    public function failed(?Throwable $e): void
    {
        app(TenantContext::class)->run($this->tenantId, function () {
            $import = Import::query()->find($this->importId);

            if ($import === null || $import->run_token !== $this->runToken) {
                return;
            }

            $import->transition([self::RUNNING[$this->action]], match ($this->action) {
                self::CHECK => Import::READY,
                self::UNDO => $import->undone_at !== null || $import->rows()->whereNotNull('undone_at')->exists() ? Import::PARTLY_UNDONE : $import->finishedStatus(),
                default => Import::FAILED,
            }, ['failure' => __('imports.errors.failed'), 'finished_at' => $import->finished_at ?? now()]);
        });
    }
}
