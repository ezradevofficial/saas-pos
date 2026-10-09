<?php

namespace App\Core\CustomFields;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * CF-01: the files of file fields, stored like item images (MD-02) on the
 * `media` disk at `tenants/{tenant}/custom-fields/{entity}/{uuid}.{ext}`,
 * never on a public path. A file is uploaded first and stays its
 * uploader's until a record stores its id (CustomFieldWriter ties it to
 * the record). Readers get a temporary URL: the object store's on s3,
 * else a signed route bound to the user (CustomFieldFileDownloadController),
 * which checks again that the user sees the record and the field.
 */
class CustomFieldFiles
{
    public const DISK = 'media';

    public const URL_MINUTES = 15;

    public const MAX_KB = 10240;

    public const MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    public function upload(CustomFieldDefinition $field, User $by, UploadedFile $file): CustomFieldFile
    {
        $mime = (string) $file->getMimeType();
        $extension = self::MIMES[$mime] ?? throw new ApiException(422, 'file_type', __('core.custom_field.file_type'), ['file' => [__('core.custom_field.file_type')]]);
        $path = sprintf('tenants/%s/custom-fields/%s/%s.%s', $this->tenants->require(), str_replace(':', '.', $field->entity), Str::uuid7(), $extension);
        $disk = Storage::disk(self::DISK);

        if (! $disk->putFileAs(dirname($path), $file, basename($path))) {
            throw new ApiException(500, 'file_not_stored', __('core.custom_field.file_not_stored'));
        }

        try {
            $stored = CustomFieldFile::create([
                'entity' => $field->entity,
                'field_key' => $field->key,
                'disk' => self::DISK,
                'path' => $path,
                'name' => mb_substr($file->getClientOriginalName() ?: basename($path), 0, 255),
                'mime' => $mime,
                'size' => (int) $file->getSize(),
                'uploaded_by' => $by->id,
            ]);

            $this->auditor->record('core.custom_field.file_upload', $stored, null, [
                'entity' => $field->entity, 'field' => $field->key, 'name' => $stored->name, 'size' => $stored->size,
            ]);

            return $stored;
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    /** Tie uploaded files to the record that now stores their ids. */
    public function attach(string $entity, string $recordId, array $fileIds): void
    {
        if ($fileIds !== []) {
            CustomFieldFile::query()->where('entity', $entity)->whereIn('id', $fileIds)->whereNull('record_id')->update(['record_id' => $recordId]);
        }
    }

    /** A temporary URL for $user. */
    public function url(CustomFieldFile $file, User $user): string
    {
        $expires = now()->addMinutes(self::URL_MINUTES);

        if (config("filesystems.disks.{$file->disk}.driver") === 's3') {
            return Storage::disk($file->disk)->temporaryUrl($file->path, $expires, [
                'ResponseContentDisposition' => 'attachment; filename="'.addcslashes($file->name, '"\\').'"',
            ]);
        }

        return URL::temporarySignedRoute('custom_fields.file', $expires, ['path' => $file->path, 'user' => $user->id]);
    }

    /** @return array{id: string, name: string, mime: string, size: int, url: ?string} */
    public function present(CustomFieldFile $file, ?User $user): array
    {
        return [
            'id' => $file->id,
            'name' => $file->name,
            'mime' => $file->mime,
            'size' => $file->size,
            'url' => $user === null ? null : $this->url($file, $user),
        ];
    }
}
