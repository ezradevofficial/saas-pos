<?php

namespace Modules\Hotel\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Exports\ListExport;
use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Hotel\Folios\FolioPayments;
use Modules\Hotel\Folios\Folios;
use Modules\Hotel\Folios\InvoiceLines;
use Modules\Hotel\Folios\ItemPrices;
use Modules\Hotel\Folios\Settlement;
use Modules\Hotel\Http\Lists\FolioList;
use Modules\Hotel\Http\Requests\ChargeFolioRequest;
use Modules\Hotel\Http\Requests\CheckPaymentRequest;
use Modules\Hotel\Http\Requests\FolioBalancesReportRequest;
use Modules\Hotel\Http\Requests\ItemPriceRequest;
use Modules\Hotel\Http\Requests\ListFoliosRequest;
use Modules\Hotel\Http\Requests\PaymentFolioRequest;
use Modules\Hotel\Http\Requests\RefundFolioRequest;
use Modules\Hotel\Http\Requests\SettleFolioRequest;
use Modules\Hotel\Http\Requests\ShowFolioRequest;
use Modules\Hotel\Http\Requests\TransferFolioRequest;
use Modules\Hotel\Http\Requests\VoidLineRequest;
use Modules\Hotel\Http\Resources\FolioResource;
use Modules\Hotel\Http\Resources\PaymentResource;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\Payment;
use Modules\Hotel\Support\Access;
use Modules\Hotel\Support\HotelError;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** HOT-19 to HOT-27: folios and everything done on them: charges, payments and deposits, refunds, transfers, voids, settlement and the print. */
class FolioController
{
    use RespondsWithLists;

    private const WITH = ['lines', 'reservation:id,number', 'guest:id,name', 'stay.room:id,number'];

    public function __construct(
        private readonly Access $access,
        private readonly Folios $folios,
        private readonly FolioPayments $payments,
        private readonly Settlement $settlement,
        private readonly InvoiceLines $invoice,
        private readonly ItemPrices $prices,
        private readonly Auditor $auditor,
    ) {}

    public function index(ListFoliosRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        return $this->folioList($request, $export, 'hotel.folio.view', false);
    }

    /** HOT-31: the folio balances report: folios with something owed or held. */
    public function balances(FolioBalancesReportRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        return $this->folioList($request, $export, 'hotel.report.view', true);
    }

    public function show(ShowFolioRequest $request, Folio $hotelFolio): FolioResource
    {
        return FolioResource::make($hotelFolio->load(self::WITH))->detail();
    }

    /** HOT-20: the folio as a statement: its lines, totals, and the invoice it settled into; the web prints it black on white. */
    public function print(ShowFolioRequest $request, Folio $hotelFolio): JsonResponse
    {
        $hotelFolio->load(self::WITH);
        $invoiceLines = $this->invoice->of($hotelFolio->id);

        return response()->json(['data' => [
            'company' => ['name' => Company::query()->whereKey($hotelFolio->company_id)->value('name')],
            'branch' => ['name' => Branch::query()->whereKey($hotelFolio->branch_id)->value('name')],
            'folio' => FolioResource::make($hotelFolio)->detail()->toArray($request),
            'stay' => $hotelFolio->stay === null ? null : ['arrival_on' => $hotelFolio->stay->arrival_on->toDateString(), 'departure_on' => $hotelFolio->stay->departure_on->toDateString(), 'board' => $hotelFolio->stay->board],
            'invoice' => $hotelFolio->invoice_number === null ? null : [
                'number' => $hotelFolio->invoice_number,
                'lines' => $invoiceLines->map(fn (FolioLine $l) => FolioResource::line($l))->values()->all(),
                ...collect($this->invoice->totals($invoiceLines))->map(fn (int $minor) => (string) $minor)->all(),
            ],
        ]]);
    }

    public function charge(ChargeFolioRequest $request, Folio $hotelFolio): JsonResponse
    {
        $data = $request->validated();
        $item = isset($data['item_id']) ? Item::query()->findOrFail($data['item_id']) : null;

        $unit = null;
        $inclusive = false;

        if ($item !== null) {
            // HOT-21: the price is master data; another price is a rate override for a holder of `hotel.rate.override`, with a reason, audited.
            $listed = $this->prices->for($hotelFolio, $item, (string) ($data['qty'] ?? '1'));
            $typed = isset($data['unit_price_minor']) ? (int) $data['unit_price_minor'] : null;
            $inclusive = $listed?->taxInclusive ?? false;
            $unit = $typed ?? ($listed === null ? null : (int) $listed->money->minor());

            if ($unit === null) {
                throw HotelError::make(422, 'price_required', field: 'unit_price_minor');
            }

            if ($listed === null || $unit !== (int) $listed->money->minor()) {
                if (! $this->access->can($request->user(), 'hotel.rate.override', $hotelFolio->branch_id)) {
                    throw HotelError::make(403, 'price_override_forbidden', field: 'unit_price_minor');
                }

                if (trim((string) ($data['reason'] ?? '')) === '') {
                    throw HotelError::make(422, 'override_reason', field: 'reason');
                }

                $this->auditor->record('hotel.folio.price_override', $hotelFolio, null, ['item_id' => $item->id, 'list_price_minor' => $listed?->money->minor(), 'price_minor' => $unit, 'reason' => $data['reason']]);
            }
        }

        $line = $item !== null
            ? $this->folios->charge($hotelFolio, [
                'kind' => FolioLine::EXTRA, 'item_id' => $item->id, 'description' => $data['description'] ?? $item->name, 'qty' => (string) ($data['qty'] ?? '1'),
                'unit_price_minor' => $unit, 'tax_inclusive' => $inclusive, 'source' => 'manual', 'source_id' => null, 'reason' => $data['reason'] ?? null,
            ], $request->user())
            : $this->folios->post($hotelFolio, [
                'kind' => FolioLine::FEE, 'description' => $data['description'], 'amount_minor' => (int) $data['amount_minor'], 'source' => 'manual', 'reason' => $data['reason'] ?? null,
                'created_by' => $request->user()->id,
            ]);

        // AUD-02: a manual fee is audited like any charge (an item charge is audited by Folios::charge).
        if ($item === null && $line->wasRecentlyCreated) {
            $this->auditor->record('hotel.folio.charge', $hotelFolio, null, ['line_id' => $line->id, 'kind' => $line->kind, 'amount_minor' => $line->amount_minor, 'description' => $line->description]);
        }

        return response()->json(['data' => FolioResource::line($line)], 201);
    }

    /** HOT-21: the price the charge dialog pre-fills, from the item and the default price list (null when there is none), and whether the user may change it. */
    public function itemPrice(ItemPriceRequest $request): JsonResponse
    {
        $folio = Folio::query()->findOrFail($request->validated('folio_id'));
        $listed = $this->prices->for($folio, Item::query()->findOrFail($request->validated('item_id')), (string) $request->validated('qty', '1'));

        return response()->json(['data' => [
            'amount_minor' => $listed === null ? null : (string) $listed->money->minor(),
            'currency' => $folio->currency,
            'tax_inclusive' => $listed?->taxInclusive ?? false,
            'can_override' => $this->access->can($request->user(), 'hotel.rate.override', $folio->branch_id),
        ]]);
    }

    public function pay(PaymentFolioRequest $request, Folio $hotelFolio): JsonResponse
    {
        $payment = $this->payments->take($hotelFolio, $request->validated(), $request->user());

        return PaymentResource::make($payment)->response()->setStatusCode($payment->status === Payment::PENDING ? 202 : 201);
    }

    public function refund(RefundFolioRequest $request, Folio $hotelFolio): JsonResponse
    {
        return PaymentResource::make($this->payments->refund($hotelFolio, $request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    public function checkPayment(CheckPaymentRequest $request, Folio $hotelFolio, Payment $hotelPayment): PaymentResource
    {
        if ($hotelPayment->folio_id !== $hotelFolio->id) {
            throw HotelError::make(404, 'payment_unknown');
        }

        return PaymentResource::make($this->payments->check($hotelPayment));
    }

    public function transfer(TransferFolioRequest $request, Folio $hotelFolio): JsonResponse
    {
        $to = Folio::query()->findOrFail($request->validated('to_folio_id'));
        $made = $this->folios->transfer($hotelFolio, $to, $request->validated('line_ids'), $request->validated('reason'), $request->user());

        return response()->json(['data' => array_map(fn (FolioLine $l) => FolioResource::line($l), $made)], 201);
    }

    public function void(VoidLineRequest $request, Folio $hotelFolio, FolioLine $hotelLine): JsonResponse
    {
        if ($hotelLine->folio_id !== $hotelFolio->id) {
            throw HotelError::make(404, 'line_unknown');
        }

        // RBAC-06: a void is money taken off the guest's bill, so it keeps to the same limit as a refund (the role's max_refund_amount; an Owner has none).
        // There is no separate void limit yet: when one is added to LimitRules it is read here first. Over the limit, a user with a higher limit does it.
        $this->payments->assertRefundLimit($hotelFolio, $request->user(), abs($hotelLine->amount_minor), 'void_above_limit');

        return response()->json(['data' => FolioResource::line($this->folios->void($hotelLine, $request->validated('reason'), $request->user()))], 201);
    }

    public function settle(SettleFolioRequest $request, Folio $hotelFolio): FolioResource
    {
        return FolioResource::make($this->settlement->settle($hotelFolio, $request->user(), $request->boolean('leave_balance'))->load(self::WITH))->detail();
    }

    private function folioList(ListFoliosRequest $request, ListExport $export, string $permission, bool $balancesOnly): AnonymousResourceCollection|StreamedResponse
    {
        $query = $this->access->scopeBranches(Folio::query()->with(FolioList::RELATIONS), $request->user(), $permission);

        if ($request->filled('branch')) {
            $query->where('branch_id', $request->validated('branch'));
        }

        $status = $request->validated('status', $balancesOnly ? 'open' : 'all');
        $status === 'all' ? null : $query->where('status', $status);

        if ($request->filled('kind')) {
            $query->where('kind', $request->validated('kind'));
        }

        if ($balancesOnly || $request->boolean('with_balance')) {
            $query->whereRaw('(select coalesce(sum(l.amount_minor), 0) from hotel_folio_lines l where l.folio_id = hotel_folios.id) <> 0');
        }

        if ($request->filled('search')) {
            $like = '%'.addcslashes((string) $request->validated('search'), '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('number', 'ilike', $like)
                ->orWhereIn('reservation_id', fn ($r) => $r->select('id')->from('hotel_reservations')->where('number', 'ilike', $like))
                ->orWhereIn('guest_id', fn ($g) => $g->select('id')->from('hotel_guests')->where('name', 'ilike', $like)));
        }

        return $this->listed($request, $query, FolioResource::class, $export);
    }
}
