<?php

namespace Modules\Salon\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Salon\Http\Requests\ActionRequest;
use Modules\Salon\Http\Requests\AdjustPackageRequest;
use Modules\Salon\Http\Requests\ResolveExceptionRequest;
use Modules\Salon\Http\Requests\ViewRequest;
use Modules\Salon\Http\Resources\Views;
use Modules\Salon\Models\Package;
use Modules\Salon\Models\SalonException;
use Modules\Salon\Packages\Packages;
use Modules\Salon\Support\Places;

/** SAL-18: packages sold, a correction of their sessions, and the credit exceptions a person looks at. */
class PackageController
{
    use Paginates;

    public function __construct(private readonly Packages $packages, private readonly Views $views, private readonly Places $places) {}

    public function index(ViewRequest $request): JsonResponse
    {
        $query = Package::query()->orderByDesc('created_at')->orderBy('id');
        $this->places->filterCompanies($query, $request->user(), $request->permission());

        foreach (['company_id', 'status'] as $filter) {
            $request->filled($filter) && $query->where($filter, $request->input($filter));
        }

        $request->filled('client_id') && $query->where('customer_id', $request->input('client_id'));

        return $this->paged($query, $request, fn ($rows) => $this->views->packages($rows));
    }

    public function show(ActionRequest $request, Package $salonPackage): JsonResponse
    {
        return response()->json(['data' => $this->views->packages(collect([$salonPackage]))[0]]);
    }

    public function adjust(AdjustPackageRequest $request, Package $salonPackage): JsonResponse
    {
        $package = $this->packages->adjust($salonPackage, (int) $request->input('sessions_used'), (string) $request->input('reason'), $request->user());

        return response()->json(['data' => $this->views->packages(collect([$package]))[0]]);
    }

    public function exceptions(ViewRequest $request): JsonResponse
    {
        $query = SalonException::query()->orderByDesc('created_at')->orderBy('id');
        $this->places->filterCompanies($query, $request->user(), $request->permission());
        $request->filled('company_id') && $query->where('company_id', $request->input('company_id'));
        $query->where('status', $request->input('status', 'open'));

        return $this->paged($query, $request, fn ($rows) => $this->views->exceptions($rows));
    }

    public function resolve(ResolveExceptionRequest $request, SalonException $salonException): JsonResponse
    {
        if ($salonException->status !== 'resolved') {
            $salonException->forceFill(['status' => 'resolved', 'note' => $request->input('note'), 'resolved_by' => $request->user()->id, 'resolved_at' => now()])->save();
        }

        return response()->json(['data' => $this->views->exceptions(collect([$salonException->refresh()]))[0]]);
    }
}
