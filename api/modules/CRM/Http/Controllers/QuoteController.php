<?php

namespace Modules\CRM\Http\Controllers;

use App\Core\DocumentTemplates\DocumentEmails;
use App\Core\DocumentTemplates\DocumentOutput;
use App\Core\DocumentTemplates\DocumentShares;
use App\Core\DocumentTemplates\Models\DocumentShare;
use App\Core\Exports\ListExport;
use App\Core\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CRM\Activities\Activities;
use Modules\CRM\Http\Requests\ListQuotesRequest;
use Modules\CRM\Http\Requests\QuoteRequest;
use Modules\CRM\Http\Requests\RecordActionRequest;
use Modules\CRM\Http\Requests\ShowRecordRequest;
use Modules\CRM\Http\Resources\QuoteResource;
use Modules\CRM\Http\Resources\Refs;
use Modules\CRM\Models\Quote;
use Modules\CRM\Printing\QuotePrints;
use Modules\CRM\Printing\QuoteSource;
use Modules\CRM\Quotes\Quotes;
use Modules\CRM\Support\CrmError;
use Modules\CRM\Support\Tx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CRM-17 to CRM-24: quotes, their flow, output and conversion. */
class QuoteController
{
    public function __construct(
        private readonly Quotes $quotes,
        private readonly QuotePrints $prints,
    ) {}

    public function index(ListQuotesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Quote::query()->with(QuoteResource::RELATIONS);
        $request->applyFilters($query);
        $request->applySort($query);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return QuoteResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function show(ShowRecordRequest $request, Quote $crmQuote): QuoteResource
    {
        return $this->detail($crmQuote);
    }

    /** CRM-18: the body priced without saving (the editor's live totals); problems listed, never thrown. */
    public function preview(QuoteRequest $request): JsonResponse
    {
        $result = $this->quotes->preview($request->validated(), $request->user());
        $problems = collect($result['problems'])->groupBy('line');

        return response()->json(['data' => [
            'currency' => $result['currency'],
            'price_list' => Refs::named($result['list']),
            'tax_inclusive' => $result['inclusive'],
            'quote_date' => $result['quote_date']->toDateString(),
            'valid_until' => $result['valid_until']->toDateString(),
            'lines' => collect($request->validated('lines'))->keys()->map(function (int $index) use ($result, $problems) {
                $line = collect($result['lines'])->firstWhere('line_no', $index + 1);

                return [
                    ...($line === null ? ['line_no' => $index + 1] : array_map(fn ($value) => is_int($value) ? (string) $value : $value, $line)),
                    'problems' => $problems->get($index, collect())->map(fn (array $problem) => ['code' => $problem['code'], 'field' => $problem['field'], 'message' => $problem['message']])->values()->all(),
                ];
            })->values()->all(),
            'totals' => array_map(fn (int $value) => (string) $value, $result['totals']),
            'max_discount_percent' => Refs::decimal($result['max_discount_percent']),
        ]]);
    }

    public function store(QuoteRequest $request): JsonResponse
    {
        return $this->detail($this->quotes->create($request->validated(), $request->user()))->response()->setStatusCode(201);
    }

    public function update(QuoteRequest $request, Quote $crmQuote): QuoteResource
    {
        return $this->detail($this->quotes->update($crmQuote, $request->validated(), $request->user()));
    }

    public function submit(RecordActionRequest $request, Quote $crmQuote): QuoteResource
    {
        return $this->detail($this->quotes->submit($crmQuote, $request->user()));
    }

    public function accept(RecordActionRequest $request, Quote $crmQuote): QuoteResource
    {
        return $this->detail($this->quotes->accept($crmQuote, (bool) $request->validated('win_opportunity', false), $request->user()));
    }

    public function decline(RecordActionRequest $request, Quote $crmQuote): QuoteResource
    {
        return $this->detail($this->quotes->decline($crmQuote, (string) $request->validated('reason'), $request->user()));
    }

    public function convert(RecordActionRequest $request, Quote $crmQuote): QuoteResource
    {
        return $this->detail($this->quotes->convert($crmQuote, (string) $request->validated('target'), $request->user()));
    }

    public function cancel(RecordActionRequest $request, Quote $crmQuote): QuoteResource
    {
        return $this->detail($this->quotes->cancel($crmQuote, $request->user(), (string) $request->validated('reason')));
    }

    /** TPL-01: the quote printed from its template (a draft without a number). */
    public function pdf(RecordActionRequest $request, Quote $crmQuote, DocumentOutput $output): Response
    {
        if ($crmQuote->status === Quote::CANCELLED) {
            throw CrmError::make('quote_not_open', status: 409);
        }

        $document = $this->prints->quote($crmQuote);

        return new Response($output->pdf($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->fileName().'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** CRM-22, TPL-04: email the PDF (to the address given, else the customer's) and log it as an email activity. */
    public function send(RecordActionRequest $request, Quote $crmQuote, DocumentEmails $emails, Activities $activities): QuoteResource
    {
        $quote = Tx::run(function () use ($request, $crmQuote, $emails, $activities) {
            $quote = $this->quotes->markSent($crmQuote);
            $document = $this->prints->quote($quote);
            $to = $request->validated('email') ?: $document->email;

            if ($to === null) {
                throw new ApiException(422, 'no_email', __('templates.errors.no_email'), ['email' => [__('templates.errors.no_email')]]);
            }

            $emails->send($document, $quote, (string) $to, (string) $request->validated('locale', app()->getLocale()), $request->user());
            $activity = $activities->create([
                'branch_id' => $quote->branch_id, 'quote_id' => $quote->id, 'kind' => 'email', 'starts_at' => now()->toIso8601String(),
                'subject' => __('crm.quotes.email_subject', ['number' => $quote->number]), 'email' => ['to' => $to, 'subject' => __('crm.quotes.email_subject', ['number' => $quote->number])],
            ], $request->user());
            $activity->forceFill(['status' => 'done', 'done_at' => now()])->save();

            return $quote;
        });

        return $this->detail($quote);
    }

    /** CRM-22, TPL-04: a public link to the PDF for WhatsApp (7 days, revocable); the quote counts as sent. */
    public function share(RecordActionRequest $request, Quote $crmQuote, DocumentShares $shares): JsonResponse
    {
        [$share, $token] = Tx::run(function () use ($request, $crmQuote, $shares) {
            $quote = $this->quotes->markSent($crmQuote);

            return $shares->create($this->prints->quote($quote), null, $request->user());
        });
        $url = DocumentShares::url($token);

        return new JsonResponse(['data' => [...self::presentShare($share), 'url' => $url, 'whatsapp_url' => 'https://wa.me/?text='.rawurlencode($url)]], 201);
    }

    public function shares(RecordActionRequest $request, Quote $crmQuote): JsonResponse
    {
        $shares = DocumentShare::query()->where('document_type', QuoteSource::TYPE)->where('record_id', $crmQuote->id)->orderByDesc('created_at')->limit(50)->get();

        return new JsonResponse(['data' => $shares->map(fn (DocumentShare $share) => self::presentShare($share))->values()]);
    }

    public function revokeShare(RecordActionRequest $request, Quote $crmQuote, DocumentShare $documentShare, DocumentShares $shares): JsonResponse
    {
        abort_unless($documentShare->document_type === QuoteSource::TYPE && $documentShare->record_id === $crmQuote->id, 404);

        return new JsonResponse(['data' => self::presentShare($shares->revoke($documentShare, $request->user()))]);
    }

    /** @return array<string, mixed> */
    private static function presentShare(DocumentShare $share): array
    {
        return [
            'id' => $share->id,
            'status' => $share->status(),
            'created_at' => $share->created_at?->toIso8601String(),
            'expires_at' => $share->expires_at->toIso8601String(),
            'revoked_at' => $share->revoked_at?->toIso8601String(),
            'access_count' => (int) $share->access_count,
        ];
    }

    private function detail(Quote $quote): QuoteResource
    {
        return QuoteResource::make($quote->refresh()->load(QuoteResource::DETAIL))->detail();
    }
}
