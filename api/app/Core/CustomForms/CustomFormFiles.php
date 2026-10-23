<?php

namespace App\Core\CustomForms;

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
use Throwable;

/**
 * CF-04: attachments of custom form records, stored like custom field files
 * (ADR 011) on the tenant files disk (TenantFiles) at
 * `tenants/{tenant}/custom-forms/{type key}/{uuid}.{ext}`, never public.
 * Uploaded first (the uploader's only), tied to the record that names them
 * on save. Readers get a temporary signed route bound to the user
 * (CustomFormFileController), which checks again that the user sees the
 * record and streams the file from its disk.
 */
class CustomFormFiles
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    public function upload(CustomFormType $type, User $by, UploadedFile $file): CustomFormAttachment
    {
        $mime = (string) $file->getMimeType();
        $extension = CustomFieldFiles::MIMES[$mime] ?? throw new ApiException(422, 'file_type', __('core.custom_field.file_type'), ['file' => [__('core.custom_field.file_type')]]);
        $path = TenantFiles::key($this->tenants->require(), 'custom-forms', $type->key, Str::uuid7().'.'.$extension);
        $diskName = TenantFiles::disk();
        $disk = Storage::disk($diskName);

        if (! $disk->putFileAs(dirname($path), $file, basename($path))) {
            throw new ApiException(500, 'file_not_stored', __('core.custom_field.file_not_stored'));
        }

        try {
            $stored = CustomFormAttachment::create([
                'type_id' => $type->id,
                'disk' => $diskName,
                'path' => $path,
                'name' => mb_substr($file->getClientOriginalName() ?: basename($path), 0, 255),
                'mime' => $mime,
                'size' => (int) $file->getSize(),
                'uploaded_by' => $by->id,
            ]);

            $this->auditor->record('core.custom_form.attachment_upload', $stored, null, ['type' => $type->key, 'name' => $stored->name, 'size' => $stored->size]);

            return $stored;
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    public function url(CustomFormAttachment $file, User $user): string
    {
        $expires = now()->addMinutes(CustomFieldFiles::URL_MINUTES);

        return SignedUrls::temporary('custom_forms.file', $expires, ['path' => $file->path, 'user' => $user->id]);
    }

    /** @return array{id: string, name: string, mime: string, size: int, url: ?string} */
    public function present(CustomFormAttachment $file, ?User $user): array
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
