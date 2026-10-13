<?php

namespace App\Core\Billing\Http\Controllers;

use App\Core\Billing\Http\Requests\ListBillingInvoicesRequest;
use App\Core\Billing\Http\Requests\PayInvoiceRequest;
use App\Core\Billing\Http\Requests\SubscriptionRequest;
use App\Core\Billing\Http\Resources\BillingInvoiceResource;
use App\Core\Billing\Http\SubscriptionPresenter;
use App\Core\Billing\Invoicing\BillingInvoicePdf;
use App\Core\Billing\Models\BillingInvoice;
use App\Core\Billing\Models\BillingPayment;
use App\Core\Billing\Payments\BillingPayments;
use App\Core\Exports\ListExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** SUB-03, SUB-04: the tenant's invoices from the platform, their PDF, and paying them. */
class BillingInvoiceController
{
    public function __construct(private readonly BillingPayments $payments) {}

    public function index(ListBillingInvoicesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = BillingInvoice::query();
        $status = $request->validated('status', 'all');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $request->applySearch($query, ['number' => 'number']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return BillingInvoiceResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(SubscriptionRequest $request, BillingInvoice $billingInvoice): JsonResponse
    {
        $resource = BillingInvoiceResource::make($billingInvoice);
        $resource->withLines = true;

        return response()->json(['data' => [
            ...$resource->resolve($request),
            'payments' => $billingInvoice->payments()->latest()->get()->map(fn (BillingPayment $p) => SubscriptionPresenter::payment($p))->all(),
        ]]);
    }

    public function pdf(SubscriptionRequest $request, BillingInvoice $billingInvoice, BillingInvoicePdf $pdf): Response
    {
        return response($pdf->render($billingInvoice, app()->getLocale()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->fileName($billingInvoice).'"',
        ]);
    }

    /** POST subscription/invoices/{id}/pay: start a payment; poll GET subscription/payments/{id}. */
    public function pay(PayInvoiceRequest $request, BillingInvoice $billingInvoice): JsonResponse
    {
        $payment = $this->payments->pay($billingInvoice, $request->validated(), $request->actor());

        return response()->json(['data' => SubscriptionPresenter::payment($payment)], 201);
    }

    /** GET subscription/payments/{id}: where a payment stands (asks the provider while it is open). */
    public function payment(SubscriptionRequest $request, BillingPayment $billingPayment): JsonResponse
    {
        return response()->json(['data' => SubscriptionPresenter::payment($this->payments->refresh($billingPayment))]);
    }
}
