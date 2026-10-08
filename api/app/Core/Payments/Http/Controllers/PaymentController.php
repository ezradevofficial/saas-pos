<?php

namespace App\Core\Payments\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Payments\Http\Requests\ListPaymentIntentsRequest;
use App\Core\Payments\Http\Requests\ListPaymentReceiptsRequest;
use App\Core\Payments\Http\Requests\MatchPaymentReceiptRequest;
use App\Core\Payments\Http\Resources\PaymentIntentResource;
use App\Core\Payments\Http\Resources\PaymentReceiptResource;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Payments\Models\PaymentReceipt;
use App\Core\Payments\PaymentIntents;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The back office's payments: a company's intents (rows of the locations
 * the user reaches, RBAC-04), the money received on its Till or Paybill
 * that matched nothing (C2B), and matching one by hand.
 */
class PaymentController
{
    public function __construct(
        private readonly PaymentIntents $intents,
        private readonly ScopeResolver $resolver,
    ) {}

    public function intents(ListPaymentIntentsRequest $request, Company $company, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = PaymentIntent::query()->where('company_id', $company->id);
        $this->limitToReach($query, $request, $company);

        $status = $request->validated('status', 'all');
        match (true) {
            $status === 'all' => null,
            in_array($status, ['unverified', 'mismatch'], true) => $query->where('verification', $status),
            default => $query->where('status', $status),
        };

        $request->applySearch($query, ['reference' => 'reference', 'receipt' => 'provider_receipt', 'account_reference' => 'account_reference']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return PaymentIntentResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function receipts(ListPaymentReceiptsRequest $request, Company $company, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = PaymentReceipt::query()->where('company_id', $company->id);

        // Receipts belong to the company: none for a user who reaches it only through a branch or location.
        if (! $request->atCompany()) {
            $query->whereRaw('false');
        }

        $status = $request->validated('status', 'unmatched');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $request->applySearch($query, ['receipt' => 'receipt', 'account_reference' => 'account_reference']);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return PaymentReceiptResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function match(MatchPaymentReceiptRequest $request, PaymentReceipt $paymentReceipt): PaymentReceiptResource
    {
        return PaymentReceiptResource::make($this->intents->match($paymentReceipt, $request->intent(), $request->user()));
    }

    private function limitToReach(Builder $query, ListPaymentIntentsRequest $request, Company $company): void
    {
        $locations = [];

        foreach (['core.payment.view', 'core.payment.match'] as $permission) {
            $visible = $this->resolver->visibleIds($request->user(), $permission);

            if ($visible->all || in_array($company->id, $visible->companyIds, true)) {
                return;
            }

            array_push($locations, ...$visible->locationIds);
        }

        $query->whereIn('location_id', array_values(array_unique($locations)));
    }
}
