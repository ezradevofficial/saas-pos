<?php

namespace Modules\Ecommerce\Delivery;

use App\Core\Http\ApiException;
use App\Core\Http\SignedUrls;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Support\EcomError;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ECOM-41: a photo as proof of delivery, through the core media pipeline:
 * the private `media` disk (object storage in production) at
 * `tenants/{tenant}/ecommerce/proofs/{delivery}/{uuid}.{ext}`, a JPEG, PNG
 * or WebP whose content really is an image (ProofPhotoRequest: size and
 * dimensions capped), never served from a public path: staff get a
 * temporary URL (the object store's own, or the signed route bound to the
 * user, which checks the tenant and the permission again,
 * ProofPhotoController), the customer gets it through the tracking token
 * (ShopController::proofPhoto).
 *
 * KE DPA (minimum data): the photo is re-encoded before it is stored, so
 * what the camera wrote beside the picture (EXIF: the place it was taken,
 * the phone) never reaches the server's disk; the orientation is applied
 * first. A photo is taken only while the delivery is picked up (review
 * L1): a later one replaces the earlier (its file removed once the change
 * commits), and once delivered the proof never changes.
 *
 * Review H3: the size the file claims is read from its header before
 * anything is decoded; more than MAX_SIDE pixels a side or MAX_PIXELS in
 * all is refused (a small file can claim a huge picture), and what is kept
 * is scaled down to STORED_SIDE. Without GD nothing is stored (review L2).
 *
 * The staff link (review L3) is a signed URL that names the user it was
 * made for: whoever holds it can open the photo for 15 minutes, and only
 * while that user may still see the delivery (ProofPhotoController). It is
 * not tied to a session, since an image tag sends none.
 */
final class ProofPhotos
{
    public const DISK = 'media';

    public const URL_MINUTES = 15;

    public const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public const MAX_SIDE = 4096;

    public const MAX_PIXELS = 12_000_000;

    public const STORED_SIDE = 1600;

    public function __construct(private readonly TenantContext $tenants) {}

    /** @throws ApiException 409 `delivery_status_invalid` unless the delivery is picked up */
    public function store(Delivery $delivery, UploadedFile $file): Delivery
    {
        if ($delivery->status !== Delivery::PICKED_UP) {
            throw EcomError::make('delivery_status_invalid', status: 409);
        }

        [$bytes, $mime] = self::withoutMetadata((string) file_get_contents($file->getRealPath()), (string) $file->getMimeType());
        $path = sprintf('tenants/%s/ecommerce/proofs/%s/%s.%s', $this->tenants->require(), $delivery->id, Str::uuid7(), self::EXTENSIONS[$mime]);
        $disk = Storage::disk(self::DISK);

        if (! $disk->put($path, $bytes)) {
            throw EcomError::make('photo_not_stored', status: 500);
        }

        try {
            return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($delivery, $path, $mime, $bytes) {
                $delivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

                if ($delivery->status !== Delivery::PICKED_UP) {
                    throw EcomError::make('delivery_status_invalid', status: 409);
                }

                $previous = $delivery->proof_photo_path;
                $delivery->forceFill([
                    'proof_kind' => $delivery->proof_kind ?? 'photo',
                    'proof_photo_path' => $path,
                    'proof_photo_mime' => $mime,
                    'proof_photo_size' => strlen($bytes),
                    'proof_photo_at' => CarbonImmutable::now(),
                ])->save();

                if ($previous !== null && $previous !== $path) {
                    DB::connection(TenantContext::CONNECTION)->afterCommit(fn () => rescue(fn () => Storage::disk(self::DISK)->delete($previous), report: true));
                }

                return $delivery;
            });
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    /** @return array{url: string, mime: ?string, at: ?string}|null a temporary link for $user */
    public function present(Delivery $delivery, User $user): ?array
    {
        if ($delivery->proof_photo_path === null) {
            return null;
        }

        $expires = now()->addMinutes(self::URL_MINUTES);
        $url = config('filesystems.disks.'.self::DISK.'.driver') === 's3'
            ? Storage::disk(self::DISK)->temporaryUrl($delivery->proof_photo_path, $expires)
            : SignedUrls::temporary('ecommerce.proof_photo', $expires, ['path' => $delivery->proof_photo_path, 'user' => $user->id]);

        return ['url' => $url, 'mime' => $delivery->proof_photo_mime, 'at' => $delivery->proof_photo_at?->toIso8601String()];
    }

    /** The file itself (private, not cached by shared caches, never sniffed). */
    public function response(Delivery $delivery): Response
    {
        $disk = Storage::disk(self::DISK);
        abort_unless($delivery->proof_photo_path !== null && $disk->exists($delivery->proof_photo_path), 404);

        return $disk->response($delivery->proof_photo_path, null, [
            'Content-Type' => $delivery->proof_photo_mime ?? 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The picture alone, as a JPEG at most STORED_SIDE a side: its size
     * checked from the header first, then decoded and encoded again (GD),
     * its EXIF orientation applied, so no metadata survives.
     *
     * @return array{0: string, 1: string} the bytes and their type
     */
    public static function withoutMetadata(string $bytes, string $mime, ?bool $gd = null): array
    {
        if (! ($gd ?? (function_exists('imagecreatefromstring') && function_exists('imagejpeg') && function_exists('imagescale')))) {
            Log::error('ecommerce: proof photo refused: the GD extension is missing');

            throw EcomError::make('photo_unavailable', status: 503);
        }

        $size = @getimagesizefromstring($bytes);

        if (! is_array($size) || $size[0] < 1 || $size[1] < 1) {
            throw EcomError::make('photo_invalid', field: 'photo');
        }

        if ($size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE || $size[0] * $size[1] > self::MAX_PIXELS) {
            throw EcomError::make('photo_pixels_too_large', ['max' => self::MAX_SIDE], 'photo');
        }

        $image = @imagecreatefromstring($bytes);

        if (! $image instanceof GdImage) {
            throw EcomError::make('photo_invalid', field: 'photo');
        }

        if ($mime === 'image/jpeg') {
            $image = self::oriented($image, $bytes);
        }

        // Scaled down first, so the copies below stay small.
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) > self::STORED_SIDE) {
            $ratio = self::STORED_SIDE / max($width, $height);
            $scaled = imagescale($image, max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));
            imagedestroy($image);

            if (! $scaled instanceof GdImage) {
                throw EcomError::make('photo_invalid', field: 'photo');
            }

            $image = $scaled;
        }

        // A transparent PNG or WebP gets a white ground, as on paper.
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imagedestroy($image);
        $image = $flat;
        ob_start();

        try {
            imagejpeg($image, null, 82);

            return [(string) ob_get_contents(), 'image/jpeg'];
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
    }

    private static function oriented(GdImage $image, string $bytes): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated instanceof GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }
}
