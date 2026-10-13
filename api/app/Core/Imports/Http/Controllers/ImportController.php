<?php

namespace App\Core\Imports\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Exports\ListExport;
use App\Core\Http\ApiException;
use App\Core\Imports\Http\Requests\ImportActionRequest;
use App\Core\Imports\Http\Requests\ImportersRequest;
use App\Core\Imports\Http\Requests\ImportRequest;
use App\Core\Imports\Http\Requests\ImportTemplateRequest;
use App\Core\Imports\Http\Requests\ListImportsRequest;
use App\Core\Imports\Http\Requests\StoreImportRequest;
use App\Core\Imports\Http\Requests\UndoImportRequest;
use App\Core\Imports\Http\Requests\UpdateImportRequest;
use App\Core\Imports\Http\Resources\ImportResource;
use App\Core\Imports\Import;
use App\Core\Imports\ImportAccess;
use App\Core\Imports\ImportColumn;
use App\Core\Imports\Importer;
use App\Core\Imports\ImportFile;
use App\Core\Imports\ImportFiles;
use App\Core\Imports\ImportLimits;
use App\Core\Imports\ImportMapper;
use App\Core\Imports\ImportRegistry;
use App\Core\Imports\ImportRunner;
use App\Core\Imports\ImportTemplates;
use App\Core\Imports\ImportUndo;
use App\Core\Imports\Jobs\RunImport;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * IMP-01..IMP-03: the import wizard's API.
 *
 *   GET    imports/importers                    what the user may import, with columns
 *   GET    imports/importers/{importer}/template ?locale=en|fr (XLSX with notes)
 *   POST   imports                              upload (multipart: importer, file); guesses the mapping
 *   GET    imports                              past imports (list, filters, export)
 *   GET    imports/{import}                     progress and counts
 *   PATCH  imports/{import}                     mapping and mode
 *   GET    imports/{import}/preview             the first 200 rows with their errors
 *   POST   imports/{import}/dry-run             202: the whole file checked in the background
 *   POST   imports/{import}/run                 202: the import, in the background
 *   POST   imports/{import}/undo                202, or 422 `import_undo_blocked` with the blocking rows
 *
 * One dry run, import or undo runs at a time per tenant and importer (409
 * `import_running`). Every step is audited as `core.import.*` (AUD-01).
 */
class ImportController
{
    public function __construct(
        private readonly ImportRegistry $registry,
        private readonly ImportMapper $mapper,
        private readonly ImportFiles $files,
        private readonly Auditor $auditor,
    ) {}

    public function importers(ImportersRequest $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => array_map(fn (Importer $importer) => [
            'key' => $importer->key(),
            'label' => $importer->label(),
            'can_update' => $importer->canRun($user, 'upsert'),
            'match_columns' => $importer->matchColumns(),
            'columns' => array_map(fn (ImportColumn $column) => $column->present(), $importer->columns($user)),
        ], $this->registry->runnable($user))]);
    }

    public function template(ImportTemplateRequest $request, ImportTemplates $templates): BinaryFileResponse
    {
        $importer = $request->importer();
        $locale = $request->validated('locale', app()->getLocale());
        $path = $templates->write($importer, $request->user(), $locale);
        $name = Str::slug(__("imports.importers.{$importer->key()}", [], $locale)).'-'.__('imports.template.file_suffix', [], $locale).'.xlsx';

        return response()->download($path, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    public function index(ListImportsRequest $request, ListExport $export, ImportAccess $access): AnonymousResourceCollection|StreamedResponse
    {
        $query = Import::query()->with('user:id,name')->whereIn('importer', $access->visibleImporters($request->user()));

        foreach (['importer', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->validated($filter));
            }
        }

        $request->applySearch($query, ['file_name' => 'file_name']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return ImportResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreImportRequest $request): JsonResponse
    {
        $user = $request->user();
        $importer = $this->registry->get($request->validated('importer'));
        $format = $request->fileFormat();
        $id = (string) Str::uuid7();
        $path = $this->files->storeSource($request->file('file'), $id, $format);

        try {
            [$local, $cleanup] = $this->files->local($path);

            try {
                $file = new ImportFile($local, $format);
                $headers = $file->headers();
                $total = $file->count(ImportLimits::MAX_ROWS);
            } finally {
                $cleanup();
            }
        } catch (Throwable $e) {
            Storage::disk(ImportFiles::DISK)->delete($path);

            throw new ApiException(422, 'file_unreadable', __('imports.errors.file_unreadable'), ['file' => [__('imports.errors.file_unreadable')]]);
        }

        $refuse = match (true) {
            $headers === [] || $total === 0 => ['file_empty', __('imports.errors.file_empty')],
            $total > ImportLimits::MAX_ROWS => ['too_many_rows', __('imports.errors.too_many_rows', ['rows' => number_format(ImportLimits::MAX_ROWS)])],
            default => null,
        };

        if ($refuse !== null) {
            Storage::disk(ImportFiles::DISK)->delete($path);

            throw new ApiException(422, $refuse[0], $refuse[1], ['file' => [$refuse[1]]]);
        }

        $import = new Import([
            'user_id' => $user->id,
            'importer' => $importer->key(),
            'mode' => 'create',
            'status' => Import::READY,
            'locale' => app()->getLocale(),
            'file_disk' => ImportFiles::DISK,
            'file_path' => $path,
            'file_name' => mb_substr($request->file('file')->getClientOriginalName() ?: "import.{$format}", 0, 255),
            'file_format' => $format,
            'file_size' => (int) $request->file('file')->getSize(),
            'headers' => $headers,
            'total_rows' => $total,
        ]);
        $import->id = $id;
        $import->mapping = $this->mapper->guess($importer, $user, $headers);
        $import->save();

        $this->auditor->record('core.import.upload', $import, null, [...$import->summary(), 'mapping' => $import->mapping]);

        return ImportResource::make($import->load('user:id,name'))->response()->setStatusCode(201);
    }

    public function show(ImportRequest $request, Import $import): ImportResource
    {
        return ImportResource::make($import->load('user:id,name'));
    }

    /** IMP-02: a new mapping or mode needs a new dry run; the mapping is kept for the next file. */
    public function update(UpdateImportRequest $request, Import $import): ImportResource
    {
        $this->assertOpen($import);
        $before = ['mapping' => $import->mapping, 'mode' => $import->mode];

        if ($request->has('mapping')) {
            $import->mapping = $request->validated('mapping');
        }

        $import->mode = $request->validated('mode', $import->mode);

        if ($import->isDirty(['mapping', 'mode'])) {
            $import->forceFill(['status' => Import::READY, 'checked' => false, 'error_file_path' => null])->save();
            $this->auditor->record('core.import.update', $import, $before, ['mapping' => $import->mapping, 'mode' => $import->mode]);
        }

        $this->mapper->remember($import);

        return ImportResource::make($import->load('user:id,name'));
    }

    public function preview(ImportActionRequest $request, Import $import, ImportRunner $runner): JsonResponse
    {
        $this->assertOpen($import);
        $this->assertMapped($request, $import);

        return response()->json(['data' => [
            ...$runner->preview($import, $request->user()),
            'total_rows' => $import->total_rows,
        ]]);
    }

    public function dryRun(ImportActionRequest $request, Import $import): JsonResponse
    {
        return $this->start($request, $import, Import::CHECKING, RunImport::CHECK, 'core.import.dry_run_start');
    }

    public function run(ImportActionRequest $request, Import $import): JsonResponse
    {
        return $this->start($request, $import, Import::IMPORTING, RunImport::IMPORT, 'core.import.start');
    }

    /** IMP-02: refused with the blocking rows when anything depends on the batch. */
    public function undo(UndoImportRequest $request, Import $import, ImportUndo $undo): JsonResponse
    {
        if (! $import->canBeUndone()) {
            throw new ApiException(422, 'import_not_undoable', __('imports.errors.not_undoable'));
        }

        $blockers = $undo->blockers($import);

        if ($blockers['count'] > 0) {
            $import->forceFill(['blocking' => $blockers['rows']])->save();

            throw new ApiException(422, 'import_undo_blocked', __('imports.errors.undo_blocked', ['count' => $blockers['count']]), extra: ['blocking' => $blockers]);
        }

        $this->transition($import, Import::UNDOING, ['blocking' => [], 'failure' => null]);
        $this->auditor->record('core.import.undo_start', $import, null, $import->summary());
        RunImport::dispatch($import->tenant_id, $import->id, RunImport::UNDO, $request->user()->id);

        return ImportResource::make($import->refresh()->load('user:id,name'))->response()->setStatusCode(202);
    }

    private function start(ImportActionRequest $request, Import $import, string $status, string $action, string $auditAction): JsonResponse
    {
        $this->assertOpen($import);
        $this->assertMapped($request, $import);

        $this->transition($import, $status, [
            'processed_rows' => 0, 'created_rows' => 0, 'updated_rows' => 0, 'failed_rows' => 0, 'failure' => null,
            'started_at' => now(), 'finished_at' => null,
        ]);
        $this->auditor->record($auditAction, $import, null, [...$import->summary(), 'mapping' => $import->mapping]);
        RunImport::dispatch($import->tenant_id, $import->id, $action);

        return ImportResource::make($import->refresh()->load('user:id,name'))->response()->setStatusCode(202);
    }

    /** One run per tenant and importer: the `imports_one_running` index says no with 409. */
    private function transition(Import $import, string $status, array $attributes): void
    {
        try {
            DB::connection(TenantContext::CONNECTION)->transaction(fn () => $import->forceFill(['status' => $status, ...$attributes])->save());
        } catch (UniqueConstraintViolationException) {
            $import->refresh();

            throw new ApiException(409, 'import_running', __('imports.errors.running'));
        }
    }

    private function assertOpen(Import $import): void
    {
        if (! $import->isOpen()) {
            throw new ApiException(409, 'import_not_open', __('imports.errors.not_open'));
        }
    }

    private function assertMapped(ImportActionRequest $request, Import $import): void
    {
        $errors = $this->mapper->errors($this->registry->get($import->importer), $request->user(), $import->headers, $import->mapping);

        if ($errors !== []) {
            throw new ApiException(422, 'mapping_incomplete', (string) collect($errors)->flatten()->first(), $errors);
        }
    }
}
