<?php

namespace Modules\HR\Records;

use App\Core\Audit\Auditor;
use App\Core\CustomFields\CustomFieldFiles;
use App\Core\Files\TenantFiles;
use App\Core\Http\ApiException;
use App\Core\Http\SignedUrls;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\HR\Models\Document;
use Modules\HR\Models\Employee;
use Modules\HR\Support\Access;
use Modules\HR\Support\Sensitive;
use Throwable;

/**
 * HR-27: documents on employees, stored as the core stores files (the
 * `media` disk, never public; the custom field file types and size) at
 * `tenants/{tenant}/hr/documents/{uuid}.{ext}`. Who sees one follows its
 * category (a sensitive group's view permission, else `hr.document.view`),
 * at the employee's place. Readers get a temporary signed route bound to them
 * (streamed from its disk), checked again on download;
 * every download is audited (HR-11).
 */
class Documents
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
        private readonly Access $access,
        private readonly Sensitive $sensitive,
    ) {}

    /** @param array{category: string, title: string, expires_on?: ?string} $data */
    public function upload(Employee $employee, array $data, UploadedFile $file, User $by): Document
    {
        $mime = (string) $file->getMimeType();
        $extension = CustomFieldFiles::MIMES[$mime] ?? throw new ApiException(422, 'file_type', __('core.custom_field.file_type'), ['file' => [__('core.custom_field.file_type')]]);
        $path = TenantFiles::key($this->tenants->require(), 'hr', 'documents', Str::uuid7().'.'.$extension);
        $diskName = TenantFiles::disk();
        $disk = Storage::disk($diskName);

        if (! $disk->putFileAs(dirname($path), $file, basename($path))) {
            throw new ApiException(500, 'file_not_stored', __('core.custom_field.file_not_stored'));
        }

        try {
            return Document::create([
                'employee_id' => $employee->id,
                'category' => $data['category'],
                'title' => $data['title'],
                'expires_on' => $data['expires_on'] ?? null,
                'disk' => $diskName,
                'path' => $path,
                'file_name' => mb_substr($file->getClientOriginalName() ?: basename($path), 0, 255),
                'mime' => $mime,
                'size' => (int) $file->getSize(),
                'uploaded_by' => $by->id,
            ]);
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    /** HR-11: a download happened, for $user (the reader the link was signed for). */
    public function downloaded(Document $document, User $user): void
    {
        $this->auditor->record('hr.document.download', $document, null, ['employee_id' => $document->employee_id, 'category' => $document->category], ['user_id' => $user->id]);
    }

    /** Whether $user may see $document (its category's permission at the employee's place, not hidden by field rules). */
    public function sees(User $user, Document $document, ?Employee $employee = null): bool
    {
        $employee ??= Employee::query()->find($document->employee_id);

        if ($employee === null) {
            return false;
        }

        $group = Sensitive::DOCUMENT_GROUPS[$document->category] ?? null;

        return $group === null ? $this->access->can($user, 'hr.document.view', $employee) : $this->sensitive->sees($user, $employee, $group);
    }

    /** @return list<string> the categories $user may see on $employee */
    public function visibleCategories(User $user, Employee $employee): array
    {
        return array_values(array_filter(Document::CATEGORIES, function (string $category) use ($user, $employee) {
            $group = Sensitive::DOCUMENT_GROUPS[$category] ?? null;

            return $group === null ? $this->access->can($user, 'hr.document.view', $employee) : $this->sensitive->sees($user, $employee, $group);
        }));
    }

    /** HR-27: a download link lives this long (each download is audited when it happens). */
    public const LINK_MINUTES = 5;

    /** A short-lived download link for $user (the download itself is audited, HR-11). */
    public function link(Document $document, User $user): string
    {
        $expires = now()->addMinutes(self::LINK_MINUTES);

        return SignedUrls::temporary('hr.document.file', $expires, ['path' => $document->path, 'user' => $user->id]);
    }
}
