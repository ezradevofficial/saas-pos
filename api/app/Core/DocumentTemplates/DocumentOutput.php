<?php

namespace App\Core\DocumentTemplates;

/**
 * TPL-04: a document printed with the template that applies to it
 * (TemplateResolver, TPL-05), as HTML for printing or as a PDF. The
 * fiscal block is required where the company's country pack says so
 * (FiscalRules, TPL-03).
 */
class DocumentOutput
{
    public function __construct(
        private readonly TemplateResolver $resolver,
        private readonly FiscalRules $fiscal,
    ) {}

    public function html(DocumentData $document): string
    {
        [$template, $required] = $this->prepare($document);

        return app(TemplateRenderer::class)->html($document->type, $template, $document->data, $required);
    }

    public function pdf(DocumentData $document): string
    {
        [$template, $required] = $this->prepare($document);

        return app(PdfRenderer::class)->pdf($document->type, $template, $document->data, $required);
    }

    /**
     * INV-21: several documents of one type at one place, a page each, with
     * the template that applies to the first.
     *
     * @param  non-empty-list<DocumentData>  $documents
     */
    public function htmlPages(array $documents): string
    {
        [$template, $required] = $this->prepare($documents[0]);

        return app(TemplateRenderer::class)->htmlPages($documents[0]->type, $template, array_map(fn (DocumentData $d) => $d->data, $documents), $required);
    }

    /** @param non-empty-list<DocumentData> $documents */
    public function pdfPages(array $documents): string
    {
        [$template, $required] = $this->prepare($documents[0]);

        return app(PdfRenderer::class)->pdfPages($documents[0]->type, $template, array_map(fn (DocumentData $d) => $d->data, $documents), $required);
    }

    /** @return array{0: array, 1: bool} */
    private function prepare(DocumentData $document): array
    {
        $template = $this->resolver->resolve($document->type, $document->companyId, $document->branchId, $document->data)['template'];

        return [$template, $this->fiscal->requiredFor($document->type, $document->country)];
    }
}
