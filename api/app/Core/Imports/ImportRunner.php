<?php

namespace App\Core\Imports;

use App\Core\Audit\AuditContext;
use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Factory as ValidationFactory;
use Throwable;

/**
 * IMP-02, IMP-03: reads an import's file row by row and runs each row
 * through its importer: cells read as their column's type, required
 * columns, the same record twice in the file, then the importer's lookups,
 * the API's Form Request (DomainRequests: permissions, scope, field rules
 * RBAC-05, CustomFieldValidator CF-03) and, when importing, the domain
 * writer.
 *
 * - preview: the first 200 rows, nothing written.
 * - dry run: every row, nothing written, an error file of the rows that
 *   would fail.
 * - import: batches of 500 rows, each in one transaction in the tenant's
 *   context (RLS, TEN-01); a refused row rolls back to its savepoint and
 *   is reported, the others are saved. Progress is stored after each
 *   batch; the result file lists every row. Every audit entry written
 *   carries the import's id (AUD-01: audited as one batch).
 */
class ImportRunner
{
    public const BATCH = 500;

    public const PREVIEW = 200;

    public function __construct(
        private readonly ImportRegistry $registry,
        private readonly ImportCells $cells,
        private readonly ImportFiles $files,
        private readonly Auditor $auditor,
        private readonly AuditContext $audit,
    ) {}

    /**
     * IMP-02: the first rows with their errors, nothing written.
     *
     * @return array{rows: list<array<string, mixed>>, valid: int, invalid: int}
     */
    public function preview(Import $import, User $user): array
    {
        $plan = $this->plan($import, $user);
        $rows = [];
        $seen = [];

        $this->withFile($import, function (ImportFile $file) use ($plan, &$rows, &$seen) {
            foreach ($file->rows() as $number => $cells) {
                $outcome = $this->evaluate($plan, $number, $cells, $seen, write: false);
                $rows[] = [
                    'row' => $number,
                    'values' => $this->shown($plan, $cells),
                    'status' => $outcome['errors'] === [] ? 'valid' : 'failed',
                    'action' => $outcome['action'],
                    'errors' => $outcome['errors'],
                ];

                if (count($rows) >= self::PREVIEW) {
                    break;
                }
            }
        });

        $invalid = count(array_filter($rows, fn (array $row) => $row['errors'] !== []));

        return ['rows' => $rows, 'valid' => count($rows) - $invalid, 'invalid' => $invalid];
    }

    /** IMP-02: the dry run over the whole file; an error file of the rows that would fail. */
    public function check(Import $import): void
    {
        $this->run($import, write: false);
    }

    /** IMP-03: the import, in batches, with progress and a result file. */
    public function import(Import $import): void
    {
        $this->run($import, write: true);
    }

    private function run(Import $import, bool $write): void
    {
        $user = User::query()->find($import->user_id);

        if ($user === null || ! $user->isActive()) {
            $this->finish($import, $write, failure: __('imports.errors.user_inactive'));

            return;
        }

        $plan = $this->plan($import, $user);
        $report = new ImportReport($import->headers, withResult: $write);
        $counts = ['processed_rows' => 0, 'created_rows' => 0, 'updated_rows' => 0, 'failed_rows' => 0];
        $verifier = $this->cachingVerifier();

        try {
            $this->audit->actingAs($user->id, ['import_id' => $import->id], function () use ($import, $plan, $report, &$counts, $write, $verifier) {
                $seen = [];
                $batch = [];
                $flush = function () use (&$batch, &$seen, &$counts, $import, $plan, $report, $write, $verifier) {
                    $verifier->forget();
                    $plan['context']->forget();
                    $write ? $this->writeBatch($import, $plan, $batch, $seen, $counts, $report) : $this->checkBatch($plan, $batch, $seen, $counts, $report);
                    $batch = [];
                    // Progress, only while the run is still this one's; else stop here.
                    if (! $import->transition([$write ? Import::IMPORTING : Import::CHECKING], $write ? Import::IMPORTING : Import::CHECKING, $counts)) {
                        throw new ImportSuperseded;
                    }
                };

                $this->withFile($import, function (ImportFile $file) use (&$batch, $flush) {
                    foreach ($file->rows() as $number => $cells) {
                        $batch[$number] = $cells;

                        if (count($batch) >= self::BATCH) {
                            $flush();
                        }
                    }
                });

                if ($batch !== []) {
                    $flush();
                }
            });
        } catch (Throwable $e) {
            $report->discard();
            $this->restoreVerifier($verifier);

            if ($e instanceof ImportSuperseded) {
                return;
            }

            report($e);
            $this->finish($import->refresh(), $write, failure: __('imports.errors.failed'));

            return;
        }

        $this->restoreVerifier($verifier);
        $path = $report->rows() > 0 ? $this->files->storeLocal($report->finish(), $import->id, $write ? 'result.xlsx' : 'errors.xlsx') : null;

        if ($path === null) {
            $report->discard();
        }

        $this->finish($import, $write, $path);
    }

    /**
     * @param  array<int, list<mixed>>  $batch
     * @param  array<string, int>  $seen
     * @param  array<string, int>  $counts
     */
    private function checkBatch(array $plan, array $batch, array &$seen, array &$counts, ImportReport $report): void
    {
        foreach ($batch as $number => $cells) {
            $outcome = $this->evaluate($plan, $number, $cells, $seen, write: false);
            $counts['processed_rows']++;

            if ($outcome['errors'] !== []) {
                $counts['failed_rows']++;
                $report->add($cells, count($plan['import']->headers), $number, null, $this->errorText($plan, $outcome['errors']));
            } else {
                $counts[$outcome['action'] === 'update' ? 'updated_rows' : 'created_rows']++;
            }
        }
    }

    /**
     * One transaction per batch, in the tenant's context; each row in its
     * own savepoint (the writers' nested transactions).
     *
     * @param  array<int, list<mixed>>  $batch
     * @param  array<string, int>  $seen
     * @param  array<string, int>  $counts
     */
    private function writeBatch(Import $import, array $plan, array $batch, array &$seen, array &$counts, ImportReport $report): void
    {
        // Lock order, as the API's writers take them: the importer's locks
        // (master data sharing, price lists) first, then the audit chain head
        // (Auditor::batch, once per batch). Never the other way round.
        $results = DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->lockedBatch($plan, fn () => $this->auditor->batch(function () use ($import, $plan, $batch, &$seen) {
            $results = [];
            $rows = [];

            foreach ($batch as $number => $cells) {
                $outcome = $this->evaluate($plan, $number, $cells, $seen, write: true);
                $results[$number] = $outcome;

                if ($outcome['written'] !== null) {
                    $rows[] = [
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $import->tenant_id,
                        'import_id' => $import->id,
                        'row_number' => $number,
                        'record_type' => $plan['importer']->key(),
                        'record_id' => (string) $outcome['written']['record']->getKey(),
                        'action' => $outcome['action'] === 'update' ? 'updated' : 'created',
                        'before' => $outcome['written']['before'] === null ? null : json_encode($outcome['written']['before'], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                        'created_at' => now(),
                    ];
                }
            }

            foreach (array_chunk($rows, 250) as $chunk) {
                DB::connection(TenantContext::CONNECTION)->table('import_rows')->insert($chunk);
            }

            return $results;
        })));

        foreach ($batch as $number => $cells) {
            $outcome = $results[$number];
            $counts['processed_rows']++;

            if ($outcome['errors'] !== []) {
                $counts['failed_rows']++;
                $result = __('imports.report.failed');
            } else {
                $updated = $outcome['action'] === 'update';
                $counts[$updated ? 'updated_rows' : 'created_rows']++;
                $result = __($updated ? 'imports.report.updated' : 'imports.report.created');
            }

            $report->add($cells, count($import->headers), $number, $result, $this->errorText($plan, $outcome['errors']));
        }
    }

    /**
     * One row through the importer.
     *
     * @param  list<mixed>  $cells
     * @param  array<string, int>  $seen  identities => the first row naming them
     * @return array{errors: list<array{column: ?string, message: string}>, action: ?string, written: ?array{record: mixed, before: ?array}}
     */
    private function evaluate(array $plan, int $number, array $cells, array &$seen, bool $write): array
    {
        /** @var Importer $importer */
        $importer = $plan['importer'];
        /** @var ImportContext $context */
        $context = $plan['context'];
        $values = [];
        $errors = [];

        foreach ($plan['mapping'] as $key => $index) {
            $value = $this->cells->read($plan['columns'][$key], $cells[$index] ?? null, $plan['fields'][$key] ?? null);

            if ($value instanceof ImportCellError) {
                $errors[$key][] = $value->message();
            } elseif ($value !== null && $value !== []) {
                $values[$key] = $value;
            }
        }

        foreach ($plan['columns'] as $key => $column) {
            if ($column->required && ! array_key_exists($key, $values) && ! isset($errors[$key])) {
                $errors[$key][] = __('imports.errors.required');
            }
        }

        $identity = $errors === [] ? $importer->identity($values) : null;

        if ($identity !== null) {
            if (isset($seen[$identity])) {
                $errors['_'][] = __('imports.errors.repeated', ['row' => $seen[$identity]]);
            } else {
                $seen[$identity] = $number;
            }
        }

        if ($errors !== []) {
            return ['errors' => $this->errorList($plan, $errors), 'action' => null, 'written' => null];
        }

        $action = null;

        try {
            $prepared = $importer->prepare($number, $values, $context);
            $action = $prepared->creates() ? 'create' : 'update';
            $importer->validate($prepared, $context);

            if (! $write) {
                $importer->check($prepared, $context);

                return ['errors' => [], 'action' => $action, 'written' => null];
            }

            $written = DB::connection(TenantContext::CONNECTION)->transaction(fn () => $importer->write($prepared, $context));

            return ['errors' => [], 'action' => $action, 'written' => $written];
        } catch (Throwable $e) {
            $rejected = RowRejected::from($e) ?? throw $e;

            return ['errors' => $this->errorList($plan, $rejected->errors), 'action' => $action, 'written' => null];
        }
    }

    /**
     * What the run needs, read once: the importer, its columns for the
     * user, the mapping (only known columns and headers), the context.
     *
     * @return array{import: Import, importer: Importer, columns: array<string, ImportColumn>, fields: array<string, mixed>, mapping: array<string, int>, context: ImportContext}
     */
    public function plan(Import $import, User $user): array
    {
        $importer = $this->registry->get($import->importer);
        $columns = collect($importer->columns($user))->keyBy('key')->all();
        $mapping = array_filter(
            (array) $import->mapping,
            fn ($index, $key) => isset($columns[$key]) && is_int($index) && $index >= 0 && $index < count($import->headers),
            ARRAY_FILTER_USE_BOTH,
        );

        return [
            'import' => $import,
            'importer' => $importer,
            'columns' => $columns,
            'fields' => $importer->customFields($user),
            'mapping' => $mapping,
            'context' => new ImportContext($user, $import->mode),
        ];
    }

    /**
     * @param  array<string, list<string>>  $errors  by column key or input field
     * @return list<array{column: ?string, message: string}>
     */
    private function errorList(array $plan, array $errors): array
    {
        $list = [];

        foreach ($errors as $field => $messages) {
            $column = isset($plan['columns'][$field]) ? $field : $plan['importer']->errorColumn((string) $field);

            foreach ((array) $messages as $message) {
                $list[] = ['column' => $column, 'message' => (string) $message];
            }
        }

        return $list;
    }

    /** @param list<array{column: ?string, message: string}> $errors */
    private function errorText(array $plan, array $errors): string
    {
        return implode('; ', array_map(function (array $error) use ($plan) {
            $column = $error['column'] === null ? null : ($plan['columns'][$error['column']] ?? null);

            return $column === null ? $error['message'] : $column->label().': '.$error['message'];
        }, $errors));
    }

    /**
     * The row's mapped cells as text, by column key.
     *
     * @param  list<mixed>  $cells
     * @return array<string, string>
     */
    private function shown(array $plan, array $cells): array
    {
        $shown = [];

        foreach ($plan['mapping'] as $key => $index) {
            $shown[$key] = ImportCells::display($cells[$index] ?? null);
        }

        return $shown;
    }

    /**
     * The run's end, only if the import is still the run's (a stale worker
     * never moves an import that went on to another state).
     */
    private function finish(Import $import, bool $write, ?string $path = null, ?string $failure = null): void
    {
        $finished = $import->transition([$write ? Import::IMPORTING : Import::CHECKING], $failure !== null ? ($write ? Import::FAILED : Import::READY) : ($write ? Import::COMPLETED : Import::CHECKED), [
            'checked' => ! $write && $failure === null ? true : $import->checked,
            'failure' => $failure,
            'finished_at' => now(),
            ...($write ? ['result_file_path' => $path, 'scope_companies' => $this->scopeCompanies($import)] : ['error_file_path' => $path]),
        ]);

        if (! $finished) {
            return;
        }

        $this->audit->actingAs($import->user_id, ['import_id' => $import->id], fn () => $this->auditor->record(
            $write ? ($failure === null ? 'core.import.complete' : 'core.import.fail') : 'core.import.dry_run',
            $import,
            null,
            [...$import->summary(), 'failure' => $failure],
        ));
    }

    /**
     * Runs $write with the importer's batch locks held (they last until the
     * batch's transaction ends).
     */
    public function lockedBatch(array $plan, callable $write): mixed
    {
        $plan['importer']->lockForBatch();

        return $write();
    }

    /**
     * RBAC-04: the companies of the records the import touched ("shared"
     * for shared ones); who may read the import must reach them all.
     *
     * @return list<string>
     */
    private function scopeCompanies(Import $import): array
    {
        $importer = $this->registry->get($import->importer);
        $companies = [];

        ImportRow::query()->where('import_id', $import->id)->select('record_id')->chunk(1000, function ($rows) use ($importer, &$companies) {
            foreach ($importer->companiesOf($rows->pluck('record_id')->all()) as $company) {
                $companies[$company ?? ImportAccess::SHARED] = true;
            }
        });

        return array_keys($companies);
    }

    /** @param callable(ImportFile): void $read */
    private function withFile(Import $import, callable $read): void
    {
        [$path, $cleanup] = $this->files->local($import->file_path);

        try {
            $read(new ImportFile($path, $import->file_format));
        } finally {
            $cleanup();
        }
    }

    private function cachingVerifier(): CachingPresenceVerifier
    {
        $factory = app(ValidationFactory::class);
        $verifier = new CachingPresenceVerifier($factory->getPresenceVerifier());
        $factory->setPresenceVerifier($verifier);

        return $verifier;
    }

    private function restoreVerifier(CachingPresenceVerifier $verifier): void
    {
        $factory = app(ValidationFactory::class);

        if ($factory->getPresenceVerifier() === $verifier) {
            $factory->setPresenceVerifier($verifier->inner());
        }
    }
}
