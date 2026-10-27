<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Pharmacy\Http\Lists\PrescriptionList;
use Modules\Pharmacy\Http\Requests\CancelPrescriptionRequest;
use Modules\Pharmacy\Http\Requests\ListPrescriptionsRequest;
use Modules\Pharmacy\Http\Requests\PrescriptionFileRequest;
use Modules\Pharmacy\Http\Requests\PrescriptionImageRequest;
use Modules\Pharmacy\Http\Requests\PrescriptionLinkRequest;
use Modules\Pharmacy\Http\Requests\ShowPrescriptionRequest;
use Modules\Pharmacy\Http\Requests\StorePrescriptionRequest;
use Modules\Pharmacy\Http\Resources\PrescriptionResource;
use Modules\Pharmacy\Models\Prescription;
use Modules\Pharmacy\Prescriptions\Prescriptions;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PHA-14, PHA-15, PHA-28: prescriptions (personal data) and their images. */
class PrescriptionController
{
    private const PATH = '#^tenants/([0-9a-f-]{36})/pharmacy/prescriptions/[0-9a-f-]{36}\.[a-z]+$#';

    public function __construct(private readonly Prescriptions $prescriptions) {}

    public function index(ListPrescriptionsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Prescription::query()->with(PrescriptionList::RELATIONS)->whereNull('archived_at');
        $request->applyFilters($query, 'location_id', null);

        if ($request->filled('from')) {
            $query->where('prescribed_on', '>=', $request->validated('from'));
        }

        if ($request->filled('to')) {
            $query->where('prescribed_on', '<=', $request->validated('to'));
        }

        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('patient_name', 'ilike', $like)->orWhere('prescriber_name', 'ilike', $like)
                ->orWhere('prescriber_licence', 'ilike', $like)->orWhere('facility', 'ilike', $like));
        }

        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return PrescriptionResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowPrescriptionRequest $request, Prescription $pharmacyPrescription): PrescriptionResource
    {
        return PrescriptionResource::make($pharmacyPrescription->load(PrescriptionList::RELATIONS));
    }

    public function store(StorePrescriptionRequest $request): JsonResponse
    {
        $prescription = $this->prescriptions->create($request->validated('location_id'), $request->validated(), $request->user());

        return PrescriptionResource::make($prescription->load(PrescriptionList::RELATIONS))->response()->setStatusCode(201);
    }

    public function cancel(CancelPrescriptionRequest $request, Prescription $pharmacyPrescription): PrescriptionResource
    {
        $prescription = $this->prescriptions->cancel($pharmacyPrescription, (string) $request->validated('reason'), $request->user());

        return PrescriptionResource::make($prescription->load(PrescriptionList::RELATIONS));
    }

    public function image(PrescriptionImageRequest $request, Prescription $pharmacyPrescription): PrescriptionResource
    {
        $prescription = $this->prescriptions->attachImage($pharmacyPrescription, $request->file('file'), $request->user());

        return PrescriptionResource::make($prescription->load(PrescriptionList::RELATIONS));
    }

    public function imageLink(PrescriptionLinkRequest $request, Prescription $pharmacyPrescription): JsonResponse
    {
        abort_unless($pharmacyPrescription->hasImage(), 404);

        return new JsonResponse(['data' => ['url' => $this->prescriptions->link($pharmacyPrescription, $request->user())]]);
    }

    /** PHA-15: the image behind its signed link (bound to one user, checked again here, audited). */
    public function download(PrescriptionFileRequest $request, TenantContext $tenants, string $path): Response
    {
        abort_unless(preg_match(self::PATH, $path, $parts) === 1 && Str::isUuid($parts[1]), 404);
        $userId = (string) $request->query('user');

        return $tenants->run($parts[1], function () use ($path, $userId) {
            $prescription = Prescription::query()->where('image_path', $path)->first();
            $user = Str::isUuid($userId) ? User::query()->find($userId) : null;

            abort_unless($prescription !== null && $user !== null && $user->isActive() && $this->prescriptions->sees($user, $prescription), 404);

            $disk = Storage::disk($prescription->image_disk);
            abort_unless($disk->exists($prescription->image_path), 404);
            $this->prescriptions->downloaded($prescription, $user);

            return $disk->download($prescription->image_path, $prescription->image_name, [
                'Content-Type' => $prescription->image_mime,
                'Cache-Control' => 'private, max-age=300',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }
}
