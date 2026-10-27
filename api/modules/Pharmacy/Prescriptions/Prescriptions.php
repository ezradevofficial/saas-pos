<?php

namespace Modules\Pharmacy\Prescriptions;

use App\Core\Audit\Auditor;
use App\Core\Files\TenantFiles;
use App\Core\Http\SignedUrls;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\CompanyClock;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Pharmacy\Models\Prescription;
use Modules\Pharmacy\Support\PharmacyError;
use Modules\Pharmacy\Support\Places;
use Throwable;

/**
 * PHA-14, PHA-15, PHA-28: prescription records.
 *
 * - create(): the back office records one (location checked under RLS and
 *   the user's scope by the request).
 * - fromTill(): the prescription a till sent with a sale (PHA-16): made
 *   once under the id the device chose, never changed by a later upload.
 * - The image is stored through TenantFiles (the tenant files disk,
 *   Object Storage in production) at `tenants/{tenant}/pharmacy/
 *   prescriptions/{uuid}.{ext}`, never public. Readers get a signed link
 *   bound to one user (5 minutes); every download is audited.
 */
class Prescriptions
{
    public const LINK_MINUTES = 5;

    public const MAX_KB = 10240;

    /** @var array<string, string> mime => extension */
    public const MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly TenantContext $tenants,
        private readonly Places $places,
        private readonly ScopeResolver $scopes,
    ) {}

    /** @param array<string, mixed> $data prescriber_name, prescriber_licence, facility, patient_name, prescribed_on and optional patient_phone, patient_birth_date, patient_party_id, notes */
    public function create(string $locationId, array $data, User $by): Prescription
    {
        $place = $this->places->require($locationId);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($locationId, $place, $data, $by) {
            $prescription = Prescription::create([
                ...$this->fields($data),
                'company_id' => $place['company_id'],
                'branch_id' => $place['branch_id'],
                'location_id' => $locationId,
                'status' => Prescription::VALID,
                'source' => Prescription::OFFICE,
                'created_by' => $by->id,
            ]);
            $this->auditor->record('pharmacy.prescription.create', $prescription, null, ['source' => Prescription::OFFICE, 'prescribed_on' => $prescription->prescribed_on->toDateString()]);

            return $prescription->refresh();
        });
    }

    /**
     * PHA-16: the prescription of a sale as the till sent it, or null when
     * none (or an unusable one) came. $sale is the SaleCompleted snapshot.
     *
     * @param  array<string, mixed>  $sale
     * @param  array<string, mixed>|null  $data
     */
    public function fromTill(array $sale, ?array $data): ?Prescription
    {
        if ($data === null || ! Str::isUuid((string) ($data['id'] ?? ''))) {
            return null;
        }

        $existing = Prescription::query()->find($data['id']);

        // Only this sale's own prescription (a re-upload) is accepted by id: another sale's, an office record
        // or one at another place is never attached, so a patient's record cannot be borrowed (PHA-28).
        if ($existing !== null) {
            return $this->isOwn($existing, $sale) ? $existing : null;
        }

        foreach (['prescriber_name', 'prescriber_licence', 'facility', 'patient_name', 'prescribed_on'] as $required) {
            if (! is_string($data[$required] ?? null) || trim($data[$required]) === '') {
                return null;
            }
        }

        // A prescription cannot be written tomorrow: one day is allowed for time zones.
        $soldOn = CarbonImmutable::parse((string) ($sale['sold_at'] ?? 'now'))->utc()->addDay()->toDateString();

        if (! $this->isDate($data['prescribed_on']) || $data['prescribed_on'] > $soldOn || (isset($data['patient_birth_date']) && ! $this->isDate((string) $data['patient_birth_date']))) {
            return null;
        }

        $cashier = (string) ($sale['cashier_id'] ?? '');

        try {
            // The id is the device's (UUID v7 made offline); `id` is guarded, so it is set explicitly.
            $prescription = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data, $sale, $cashier) {
                $made = new Prescription([
                    ...$this->fields($data),
                    'company_id' => $sale['company_id'],
                    'branch_id' => $sale['branch_id'],
                    'location_id' => $sale['location_id'],
                    'status' => Prescription::VALID,
                    'source' => Prescription::TILL,
                    'sale_id' => $sale['id'],
                    'created_by' => Str::isUuid($cashier) ? $cashier : null,
                ]);
                $made->id = $data['id'];
                $made->save();

                return $made;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = Prescription::query()->find($data['id']);

            return $existing !== null && $this->isOwn($existing, $sale) ? $existing : null;
        }

        $this->auditor->record('pharmacy.prescription.create', $prescription, null, ['source' => Prescription::TILL, 'sale_id' => $sale['id']], ['user_id' => Str::isUuid($cashier) ? $cashier : null]);

        return $prescription;
    }

    /** Whether $prescription was made by the till for this very sale, at this company and place. @param array<string, mixed> $sale */
    private function isOwn(Prescription $prescription, array $sale): bool
    {
        return $prescription->sale_id !== null && $prescription->sale_id === (string) $sale['id']
            && $prescription->company_id === (string) $sale['company_id'] && $prescription->location_id === (string) $sale['location_id'];
    }

    /**
     * NFR-08, PHA-28: a patient's erasure deletes the stored images of their
     * prescriptions (the records stay, TEN-06). Returns the note for the
     * erasure report.
     *
     * @param  Closure(Builder, string): void  $patientRows
     * @return list<string>
     */
    public static function eraseImagesOf(string $subjectId, Closure $patientRows): array
    {
        $table = DB::connection(TenantContext::CONNECTION)->table('pharmacy_prescriptions');
        $patientRows($table, $subjectId);
        $rows = $table->whereNotNull('image_path')->get(['id', 'image_disk', 'image_path']);

        foreach ($rows as $row) {
            Storage::disk($row->image_disk)->delete($row->image_path);
        }

        if ($rows->isNotEmpty()) {
            DB::connection(TenantContext::CONNECTION)->table('pharmacy_prescriptions')->whereIn('id', $rows->pluck('id')->all())
                ->update(['image_disk' => null, 'image_path' => null, 'image_name' => null, 'image_mime' => null]);
        }

        return $rows->isEmpty() ? [] : [__('pharmacy.privacy.images_removed', ['count' => $rows->count()])];
    }

    /** Whether the till named a prescription id that exists but is not this sale's (it is flagged, never used). @param array<string, mixed> $sale @param array<string, mixed>|null $data */
    public function isReused(array $sale, ?array $data): bool
    {
        if (! is_array($data) || ! Str::isUuid((string) ($data['id'] ?? ''))) {
            return false;
        }

        $existing = Prescription::query()->find($data['id']);

        return $existing !== null && ! $this->isOwn($existing, $sale);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function fields(array $data): array
    {
        $text = fn (string $key, int $max) => isset($data[$key]) && $data[$key] !== '' ? mb_substr(trim((string) $data[$key]), 0, $max) : null;

        return [
            'prescriber_name' => mb_substr(trim((string) $data['prescriber_name']), 0, 150),
            'prescriber_licence' => mb_substr(trim((string) $data['prescriber_licence']), 0, 60),
            'facility' => mb_substr(trim((string) $data['facility']), 0, 150),
            'patient_name' => mb_substr(trim((string) $data['patient_name']), 0, 150),
            'patient_phone' => $text('patient_phone', 30),
            'patient_birth_date' => isset($data['patient_birth_date']) && $data['patient_birth_date'] !== '' ? $data['patient_birth_date'] : null,
            'patient_party_id' => isset($data['patient_party_id']) && Str::isUuid((string) $data['patient_party_id']) ? $data['patient_party_id'] : null,
            'prescribed_on' => $data['prescribed_on'],
            'notes' => $text('notes', 2000),
        ];
    }

    private function isDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && CarbonImmutable::createFromFormat('Y-m-d', $value) !== false && CarbonImmutable::createFromFormat('Y-m-d', $value)->toDateString() === $value;
    }

    /** PHA-14: whether $prescription is older than the company's validity period on $today (never when the period is not set). */
    public static function isStale(Prescription $prescription, ?int $validDays, string $today): bool
    {
        return $validDays !== null && CarbonImmutable::parse($today)->greaterThan($prescription->prescribed_on->addDays($validDays));
    }

    public function cancel(Prescription $prescription, string $reason, User $by): Prescription
    {
        if ($prescription->status === Prescription::CANCELLED) {
            throw PharmacyError::make(409, 'prescription_cancelled');
        }

        $prescription->fill(['status' => Prescription::CANCELLED, 'cancelled_at' => CarbonImmutable::now(), 'cancelled_by' => $by->id, 'cancel_reason' => $reason])->save();
        $this->auditor->record('pharmacy.prescription.cancel', $prescription, ['status' => Prescription::VALID], ['status' => Prescription::CANCELLED, 'reason' => $reason]);

        return $prescription->refresh();
    }

    /** PHA-15: store the image (replacing an earlier one). */
    public function attachImage(Prescription $prescription, UploadedFile $file, User $by): Prescription
    {
        $mime = (string) $file->getMimeType();
        $extension = self::MIMES[$mime] ?? throw PharmacyError::make(422, 'file_type', field: 'file');
        $path = TenantFiles::key($this->tenants->require(), 'pharmacy', 'prescriptions', Str::uuid7().'.'.$extension);
        $diskName = TenantFiles::disk();
        $disk = Storage::disk($diskName);

        if (! $disk->putFileAs(dirname($path), $file, basename($path))) {
            throw PharmacyError::make(500, 'file_not_stored');
        }

        try {
            $old = [$prescription->image_disk, $prescription->image_path];
            $prescription->fill([
                'image_disk' => $diskName,
                'image_path' => $path,
                'image_name' => mb_substr($file->getClientOriginalName() ?: basename($path), 0, 255),
                'image_mime' => $mime,
                'image_size' => (int) $file->getSize(),
                'image_by' => $by->id,
            ])->save();
            $this->auditor->record('pharmacy.prescription.image', $prescription, null, ['name' => $prescription->image_name, 'size' => $prescription->image_size, 'replaced' => $old[1] !== null]);

            // The earlier image goes only once the new one is stored.
            if ($old[1] !== null) {
                Storage::disk($old[0])->delete($old[1]);
            }

            return $prescription->refresh();
        } catch (Throwable $e) {
            $disk->delete($path);

            throw $e;
        }
    }

    /** A short-lived download link for $user (the download is audited when it happens). */
    public function link(Prescription $prescription, User $user): string
    {
        return SignedUrls::temporary('pharmacy.prescription.file', now()->addMinutes(self::LINK_MINUTES), ['path' => $prescription->image_path, 'user' => $user->id]);
    }

    /** Whether $user may read $prescription's image now. */
    public function sees(User $user, Prescription $prescription): bool
    {
        return $prescription->archived_at === null
            && $this->scopes->can($user, 'pharmacy.prescription.image', Scope::location($prescription->location_id));
    }

    public function downloaded(Prescription $prescription, User $user): void
    {
        $this->auditor->record('pharmacy.prescription.download', $prescription, null, ['name' => $prescription->image_name], ['user_id' => $user->id]);
    }

    /** The company's day, for staleness checks. */
    public function today(Prescription $prescription): string
    {
        return CompanyClock::today($prescription->company_id, $prescription->branch_id);
    }
}
