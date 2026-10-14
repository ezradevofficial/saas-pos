<?php

namespace Modules\Accounting\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Accounting\Http\Lists\PaymentList;
use Modules\Accounting\Http\Requests\AllocatePaymentRequest;
use Modules\Accounting\Http\Requests\ListPaymentsRequest;
use Modules\Accounting\Http\Requests\ShowPaymentRequest;
use Modules\Accounting\Http\Requests\StorePaymentRequest;
use Modules\Accounting\Http\Resources\PaymentResource;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Payments\Payments;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** ACC-10: receipts and payments, and their allocation to documents. */
class PaymentController
{
    public function __construct(private readonly Payments $payments) {}

    public function index(ListPaymentsRequest $request, Company $company, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = $request->filter(Payment::query()->with(PaymentList::RELATIONS), 'date');

        if ($request->filled('kind')) {
            $query->where('kind', $request->validated('kind'));
        }

        if ($request->filled('party')) {
            $query->where('party_id', $request->validated('party'));
        }

        $request->applySearch($query, ['number' => 'number', 'reference' => 'reference', 'memo' => 'memo']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return PaymentResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StorePaymentRequest $request, Company $company): JsonResponse
    {
        return PaymentResource::make($this->payments->record($company, $request->validated(), $request->user()))->detail()->response()->setStatusCode(201);
    }

    public function show(ShowPaymentRequest $request, Payment $accPayment): PaymentResource
    {
        return PaymentResource::make($accPayment)->detail();
    }

    public function allocate(AllocatePaymentRequest $request, Payment $accPayment): PaymentResource
    {
        $date = $request->validated('date');

        return PaymentResource::make($this->payments->allocate($accPayment, $request->validated('allocations'), $request->user(), $date === null ? null : CarbonImmutable::parse($date)))->detail();
    }
}
