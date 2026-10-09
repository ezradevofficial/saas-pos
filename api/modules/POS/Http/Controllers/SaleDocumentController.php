<?php

namespace Modules\POS\Http\Controllers;

use App\Core\DocumentTemplates\DocumentEmails;
use App\Core\DocumentTemplates\DocumentOutput;
use App\Core\DocumentTemplates\DocumentShares;
use App\Core\DocumentTemplates\Models\DocumentShare;
use App\Core\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\POS\Documents\ReceiptData;
use Modules\POS\Http\Requests\SaleDocumentRequest;
use Modules\POS\Models\Sale;

/**
 * TPL-04: a sale's receipt from the back office, printed with the
 * template that applies to it (TPL-05): HTML for the browser's print,
 * a PDF download, an email with the PDF (queued, rate-limited, audited)
 * and a public link for WhatsApp (7 days, revocable, opens audited).
 */
class SaleDocumentController
{
    public function __construct(
        private readonly ReceiptData $receipts,
        private readonly DocumentOutput $output,
    ) {}

    public function receipt(SaleDocumentRequest $request, Sale $posSale): Response
    {
        $document = $this->receipts->sale($posSale);

        if ($request->validated('format', 'html') === 'pdf') {
            return new Response($this->output->pdf($document), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$document->fileName().'"',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return new Response($this->output->html($document), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'private, no-store',
            // The HTML is shown in a sandboxed frame for printing: nothing in it may run.
            'Content-Security-Policy' => "default-src 'none'; img-src data:; style-src 'unsafe-inline'",
        ]);
    }

    public function email(SaleDocumentRequest $request, Sale $posSale, DocumentEmails $emails): JsonResponse
    {
        $document = $this->receipts->sale($posSale);
        $to = $request->validated('email') ?: $document->email;

        if ($to === null) {
            throw new ApiException(422, 'no_email', __('templates.errors.no_email'), ['email' => [__('templates.errors.no_email')]]);
        }

        $emails->send($document, $posSale, $to, $request->validated('language', app()->getLocale()), $request->user());

        return new JsonResponse(['data' => ['queued' => true, 'to' => $to]], 202);
    }

    public function share(SaleDocumentRequest $request, Sale $posSale, DocumentShares $shares): JsonResponse
    {
        $document = $this->receipts->sale($posSale);
        [$share, $token] = $shares->create($document, $posSale->location_id, $request->user());
        $url = DocumentShares::url($token);

        return new JsonResponse(['data' => [
            ...$this->present($share),
            'url' => $url,
            'whatsapp_url' => 'https://wa.me/?text='.rawurlencode($url),
        ]], 201);
    }

    public function shares(SaleDocumentRequest $request, Sale $posSale): JsonResponse
    {
        $shares = DocumentShare::query()->where('document_type', 'pos.receipt')->where('record_id', $posSale->id)
            ->orderByDesc('created_at')->limit(50)->get();

        return new JsonResponse(['data' => $shares->map(fn (DocumentShare $share) => $this->present($share))->values()]);
    }

    public function revoke(SaleDocumentRequest $request, Sale $posSale, DocumentShare $documentShare, DocumentShares $shares): JsonResponse
    {
        return new JsonResponse(['data' => $this->present($shares->revoke($documentShare, $request->user()))]);
    }

    private function present(DocumentShare $share): array
    {
        return [
            'id' => $share->id,
            'status' => $share->status(),
            'created_at' => $share->created_at?->toIso8601String(),
            'expires_at' => $share->expires_at->toIso8601String(),
            'revoked_at' => $share->revoked_at?->toIso8601String(),
            'access_count' => (int) $share->access_count,
            'last_accessed_at' => $share->last_accessed_at?->toIso8601String(),
        ];
    }
}
