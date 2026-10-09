<?php

namespace App\Core\DocumentTemplates\Http\Controllers;

use App\Core\DocumentTemplates\DataSources;
use App\Core\DocumentTemplates\DocumentOutput;
use App\Core\DocumentTemplates\DocumentShares;
use App\Core\DocumentTemplates\Http\Requests\OpenSharedDocumentRequest;
use App\Core\DocumentTemplates\RecordSource;
use App\Core\DocumentTemplates\TemplateRenderer;
use Illuminate\Http\Response;

/**
 * TPL-04: GET /d/{token}, a shared document's PDF, for anyone holding the
 * link (WhatsApp). The 48-character random token is the credential; its
 * tenant is found by a security-definer function and everything else is
 * read under that tenant's row-level security (ADR 002). Unknown,
 * expired and revoked links answer the same page (410 once known as
 * expired or revoked, 404 otherwise); every open is counted and audited.
 */
class SharedDocumentController
{
    public function __invoke(OpenSharedDocumentRequest $request, string $token, DocumentShares $shares, DataSources $sources, DocumentOutput $output): Response
    {
        $share = $shares->resolve($token);

        if ($share === null || ! $share->isActive()) {
            return $this->gone($share === null ? 404 : 410);
        }

        $source = $sources->find($share->document_type);
        $document = $source instanceof RecordSource ? $source->load($share->record_id) : null;

        if ($document === null) {
            return $this->gone(410);
        }

        $shares->recordOpen($share, $request->ip(), $request->userAgent());

        return new Response($output->pdf($document), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->fileName().'"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function gone(int $status): Response
    {
        $message = TemplateRenderer::escape(__('templates.errors.share_expired'));

        return new Response(
            '<!doctype html><html lang="'.app()->getLocale().'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$message.'</title></head>'
            .'<body style="margin:0;padding:24px;background:#fff;color:#000;font-family:Geist,Arial,sans-serif;font-size:16px;line-height:1.5"><p>'.$message.'</p></body></html>',
            $status,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow'],
        );
    }
}
