<?php

namespace App\Core\Fiscal\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Fiscal\FiscalQueue;
use App\Core\Fiscal\Http\Requests\FiscalSubmissionRequest;
use App\Core\Fiscal\Http\Requests\ListFiscalSubmissionsRequest;
use App\Core\Fiscal\Http\Requests\RetryFiscalSubmissionRequest;
use App\Core\Fiscal\Http\Resources\FiscalSubmissionResource;
use App\Core\Fiscal\Models\FiscalSubmission;
use App\Core\Http\ApiException;
use App\Core\Tenancy\Models\Company;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The fiscal queue in the back office: list, detail and retry. */
class FiscalSubmissionController
{
    public function __construct(private readonly FiscalQueue $queue) {}

    public function index(ListFiscalSubmissionsRequest $request, Company $company, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = FiscalSubmission::query()->where('company_id', $company->id);
        $status = $request->validated('status', 'all');

        match ($status) {
            'all' => null,
            'pending' => $query->whereIn('status', ['queued', 'sending', 'retrying']),
            default => $query->where('status', $status),
        };

        $request->applySearch($query, ['document_number' => 'document_number']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return FiscalSubmissionResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(FiscalSubmissionRequest $request, FiscalSubmission $fiscalSubmission): FiscalSubmissionResource
    {
        $resource = FiscalSubmissionResource::make($fiscalSubmission);
        $resource->withPayload = true;

        return $resource;
    }

    public function retry(RetryFiscalSubmissionRequest $request, FiscalSubmission $fiscalSubmission): FiscalSubmissionResource
    {
        if ($fiscalSubmission->status === 'accepted') {
            throw new ApiException(422, 'already_accepted', __('fiscal.errors.already_accepted'));
        }

        return FiscalSubmissionResource::make($this->queue->retry($fiscalSubmission)->refresh());
    }
}
