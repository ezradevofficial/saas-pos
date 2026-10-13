<?php

namespace App\Core\Imports\Http\Resources;

use App\Core\Imports\Import;
use App\Core\Imports\ImportAccess;
use App\Core\Imports\ImportFiles;
use App\Core\Imports\ImportRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * IMP-02, IMP-03: an import with its progress, counts, the person who
 * uploaded it, what the reader may do with it, and temporary download
 * links of its error and result files, bound to the reader.
 *
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Import $import */
        $import = $this->resource;
        $user = $request->user();
        $access = app(ImportAccess::class);
        $files = app(ImportFiles::class);
        $base = pathinfo($import->file_name, PATHINFO_FILENAME);

        return [
            'id' => $import->id,
            'importer' => $import->importer,
            'importer_label' => app(ImportRegistry::class)->find($import->importer)?->label() ?? $import->importer,
            'mode' => $import->mode,
            'status' => $import->status,
            'file_name' => $import->file_name,
            'file_format' => $import->file_format,
            'file_size' => $import->file_size,
            'headers' => $import->headers,
            'mapping' => (object) $import->mapping,
            'total_rows' => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'created_rows' => $import->created_rows,
            'updated_rows' => $import->updated_rows,
            'failed_rows' => $import->failed_rows,
            'checked' => $import->checked,
            'failure' => $import->failure,
            'blocking' => $import->blocking,
            'user' => $import->relationLoaded('user') && $import->user !== null ? ['id' => $import->user->id, 'name' => $import->user->name] : ['id' => $import->user_id, 'name' => null],
            'created_at' => $import->created_at?->toIso8601ZuluString(),
            'started_at' => $import->started_at?->toIso8601ZuluString(),
            'finished_at' => $import->finished_at?->toIso8601ZuluString(),
            'undone_at' => $import->undone_at?->toIso8601ZuluString(),
            'undo_until' => $import->undoUntil()?->toIso8601ZuluString(),
            'can_change' => $user !== null && $import->isOpen() && $access->change($user, $import),
            'can_undo' => $user !== null && $import->canBeUndone() && $access->undo($user, $import),
            'error_file_url' => $user === null || $import->error_file_path === null ? null : $files->url($import->error_file_path, $user, "{$base}-errors.xlsx"),
            'result_file_url' => $user === null || $import->result_file_path === null ? null : $files->url($import->result_file_path, $user, "{$base}-result.xlsx"),
        ];
    }
}
