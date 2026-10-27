<?php

namespace Modules\Hotel\Http\Controllers;

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
use Modules\Hotel\Folios\Settlement;
use Modules\Hotel\Http\Lists\FolioList;
use Modules\Hotel\Http\Requests\ChargeFolioRequest;
use Modules\Hotel\Http\Requests\CheckPaymentRequest;
use Modules\Hotel\Http\Requests\FolioBalancesReportRequest;
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

        $line = $item !== null
            ? $this->folios->charge($hotelFolio, [
                'kind' => FolioLine::EXTRA, 'item_id' => $item->id, 'description' => $data['description'] ?? $item->name, 'qty' => (string) ($data['qty'] ?? '1'),
                'unit_price_minor' => (int) $data['unit_price_minor'], 'source' => 'manual', 'source_id' => null, 'reason' => $data['reason'] ?? null,
            ], $request->user())
            : $this->folios->post($hotelFolio, [
                'kind' => FolioLine::FEE, 'description' => $data['description'], 'amount_minor' => (int) $data['amount_minor'], 'source' => 'manual', 'reason' => $data['reason'] ?? null,
                'created_by' => $request->user()->id,
            ]);

        return response()->json(['data' => FolioResource::line($line)], 201);
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
