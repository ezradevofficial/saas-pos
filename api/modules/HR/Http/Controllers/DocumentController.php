<?php

namespace Modules\HR\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\HR\Http\Requests\DocumentRequest;
use Modules\HR\Http\Requests\HrFileDownloadRequest;
use Modules\HR\Http\Requests\ListDocumentsRequest;
use Modules\HR\Http\Requests\StoreDocumentRequest;
use Modules\HR\Http\Resources\DocumentResource;
use Modules\HR\Models\Document;
use Modules\HR\Models\Employee;
use Modules\HR\Records\Documents;
use Symfony\Component\HttpFoundation\Response;

/**
 * HR-27: documents on an employee, by the categories the reader sees, and
 * their download behind a temporary signed URL bound to the reader: the
 * path names the tenant; the controller enters it, finds the document
 * there (RLS) and checks again that the user sees it. Always a download.
 */
class DocumentController
{
    private const PATH = '#^tenants/([0-9a-f\-]{36})/hr/documents/[0-9a-f\-]{36}\.(pdf|jpg|png|webp|txt|csv|docx|xlsx)\z#';

    public function __construct(private readonly Documents $documents) {}

    public function index(ListDocumentsRequest $request, Employee $hrEmployee): AnonymousResourceCollection
    {
        $status = $request->validated('status', 'active');

        return DocumentResource::collection(Document::query()->where('employee_id', $hrEmployee->id)
            ->whereIn('category', $this->documents->visibleCategories($request->user(), $hrEmployee))
            ->when($status === 'active', fn ($q) => $q->whereNull('archived_at'))
            ->when($status === 'archived', fn ($q) => $q->whereNotNull('archived_at'))
            ->orderByDesc('created_at')->orderByDesc('id')->get());
    }

    public function store(StoreDocumentRequest $request, Employee $hrEmployee): JsonResponse
    {
        $document = $this->documents->upload($hrEmployee, $request->safe()->except('file'), $request->file('file'), $request->user());

        return DocumentResource::make($document)->response()->setStatusCode(201);
    }

    public function link(DocumentRequest $request, Document $hrDocument): JsonResponse
    {
        abort_if($hrDocument->isArchived(), 404);

        return new JsonResponse(['data' => ['url' => $this->documents->link($hrDocument, $request->user())]]);
    }

    public function archive(DocumentRequest $request, Document $hrDocument): DocumentResource
    {
        if (! $hrDocument->isArchived()) {
            $hrDocument->archive();
        }

        return DocumentResource::make($hrDocument->refresh());
    }

    public function download(HrFileDownloadRequest $request, TenantContext $tenants, string $path): Response
    {
        abort_unless(preg_match(self::PATH, $path, $parts) === 1 && Str::isUuid($parts[1]), 404);
        $userId = (string) $request->query('user');

        return $tenants->run($parts[1], function () use ($path, $userId) {
            $document = Document::query()->where('path', $path)->whereNull('archived_at')->first();
            $user = Str::isUuid($userId) ? User::query()->find($userId) : null;

            abort_unless($document !== null && $user !== null && $user->isActive() && $this->documents->sees($user, $document), 404);

            $disk = Storage::disk($document->disk);
            abort_unless($disk->exists($document->path), 404);

            return $disk->download($document->path, $document->file_name, [
                'Content-Type' => $document->mime,
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }
}
